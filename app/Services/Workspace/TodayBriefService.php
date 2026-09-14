<?php

namespace App\Services\Workspace;

use App\Enums\OwnerLocale;
use App\Models\User;
use App\Services\Commitments\CommitmentService;
use App\Services\ExecutiveBrief\ExecutiveBriefService;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\OperationalControl\ProactiveProposalService;
use App\Services\Reminders\ReminderService;
use App\Services\Reports\ScheduledReportService;
use App\Services\Sources\MultiAccountCalendarAggregator;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;
use App\Support\OwnerCopy;
use Carbon\CarbonImmutable;

final class TodayBriefService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly ReminderService $reminders,
        private readonly JarvisNotificationService $notifications,
        private readonly ScheduledReportService $reports,
        private readonly IntegrationAccountService $accounts,
        private readonly GoogleCalendarService $calendar,
        private readonly OwnerLocaleResolver $locales,
        private readonly CommitmentService $commitments,
        private readonly ExecutiveBriefService $briefs,
        private readonly ProactiveProposalService $proposals,
    ) {}

    /**
     * Attention-reduced Today payload from existing domains plus commitment deadlines.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $timezone = (string) ($user->timezone ?: 'UTC');
        $now = CarbonImmutable::now($timezone);
        $locale = $this->locales->interfaceLocale($user);
        $brief = null;
        try {
            $brief = $this->briefs->latestForToday($user);
        } catch (\Throwable) {
            $brief = null;
        }

        $serialized = $brief !== null ? $this->briefs->serialize($brief) : null;
        $sections = is_array($serialized['sections'] ?? null) ? $serialized['sections'] : [];
        $commitments = $this->itemsFrom($sections, ['overdue', 'commitments', 'today']);
        if ($commitments === []) {
            $commitments = $this->safeCommitments($user);
        }

        $proactive = $this->topProactive($user);
        $attention = $sections['attention'] ?? [];
        if ($proactive !== []) {
            $attention = array_slice(array_merge($proactive, $attention), 0, 5);
        }

        return [
            'brand' => 'LAVR',
            'date_label' => $now->locale($locale->value)->isoFormat('dddd, D MMMM'),
            'summary' => $serialized['summary'] ?? $this->summary($locale, 0, 0, 0, count($sections['meetings'] ?? [])),
            'executive_brief' => $serialized,
            'attention' => $attention,
            'today_items' => array_merge($sections['today'] ?? [], $sections['meetings'] ?? []),
            'tasks' => [],
            'reminders' => [],
            'notifications' => [],
            'reports' => [],
            'calendar' => $this->calendarFromBrief($sections),
            'calendar_hint' => null,
            'calendar_error' => $this->briefError($serialized, $locale),
            'commitments' => $commitments !== [] ? $commitments : $this->safeCommitments($user),
            'proactive' => $proactive,
            'ask_href' => '/lavr',
            'brief_href' => $serialized['href'] ?? '/lavr/briefs',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeTaskPanel(User $user): array
    {
        try {
            return $this->tasks->panelFor($user);
        } catch (\Throwable) {
            return ['today' => [], 'overdue' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeReminderPanel(User $user): array
    {
        try {
            return $this->reminders->panelFor($user);
        } catch (\Throwable) {
            return ['upcoming' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeInbox(User $user): array
    {
        try {
            return $this->notifications->panelFor($user, true);
        } catch (\Throwable) {
            return ['unread_count' => 0, 'items' => []];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topProactive(User $user): array
    {
        try {
            $rows = $this->proposals->pendingForUser($user, 3);
        } catch (\Throwable) {
            return [];
        }

        $items = [];
        foreach ($rows as $proposal) {
            if (! in_array($proposal->severity?->value, ['critical', 'high'], true)) {
                continue;
            }
            $items[] = [
                'id' => $proposal->id,
                'title' => $proposal->title,
                'summary' => $proposal->rationale,
                'href' => '/lavr/proactive/'.$proposal->id,
                'dedupe_key' => 'proactive:'.$proposal->id,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeCommitments(User $user): array
    {
        try {
            if (! $user->canUseCapability(UserCapability::COMMITMENTS)) {
                return [];
            }

            return array_map(
                fn ($commitment): array => $this->commitments->serializeSummary($commitment),
                $this->commitments->attentionForToday($user),
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeReportPanel(User $user): array
    {
        try {
            return $this->reports->panelFor($user);
        } catch (\Throwable) {
            return ['items' => []];
        }
    }

    /**
     * @return array{events: list<array<string, mixed>>, hint: ?string, error: ?string}
     */
    private function safeCalendar(User $user, CarbonImmutable $now, OwnerLocale $locale): array
    {
        if (! $user->canUseCapability(UserCapability::GOOGLE_CALENDAR)) {
            return [
                'events' => [],
                'hint' => OwnerCopy::get('today.calendar_ask_chat', $locale),
                'error' => null,
            ];
        }

        try {
            $aggregated = app(MultiAccountCalendarAggregator::class)->listEvents(
                $user,
                [
                    'time_min' => $now->startOfDay()->utc()->toIso8601String(),
                    'time_max' => $now->endOfDay()->utc()->toIso8601String(),
                    'max_results' => 8,
                    'order_by' => 'startTime',
                    'single_events' => true,
                ],
            );

            if ($aggregated['semantics'] === 'unknown') {
                return [
                    'events' => [],
                    'hint' => OwnerCopy::get('today.calendar_not_connected', $locale),
                    'error' => null,
                ];
            }

            $events = [];
            foreach (array_slice($aggregated['events'], 0, 8) as $event) {
                $events[] = [
                    'id' => (string) ($event['id'] ?? ''),
                    'title' => (string) ($event['title'] ?? OwnerCopy::get('today.event_fallback', $locale)),
                    'when_label' => $this->eventWhenLabel($event, $now, $locale),
                ];
            }

            $error = $aggregated['unavailable'] !== []
                ? OwnerCopy::get('today.calendar_unavailable', $locale)
                : null;

            return [
                'events' => $events,
                'hint' => $events === [] && $error === null ? OwnerCopy::get('today.calendar_empty', $locale) : null,
                'error' => $aggregated['semantics'] === 'unknown' ? OwnerCopy::get('today.calendar_unavailable', $locale) : $error,
            ];
        } catch (\Throwable) {
            return [
                'events' => [],
                'hint' => null,
                'error' => OwnerCopy::get('today.calendar_unavailable', $locale),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventWhenLabel(array $event, CarbonImmutable $now, OwnerLocale $locale): string
    {
        $start = (string) ($event['start'] ?? '');

        if ($start === '') {
            return '';
        }

        try {
            $at = CarbonImmutable::parse($start)->setTimezone($now->getTimezone()->getName());

            if (! empty($event['all_day'])) {
                return OwnerCopy::get('today.all_day', $locale);
            }

            return $at->format('H:i');
        } catch (\Throwable) {
            return $start;
        }
    }

    private function summary(OwnerLocale $locale, int $dueTasks, int $reminders, int $unread, int $events): string
    {
        $parts = [];

        if ($dueTasks > 0) {
            $parts[] = OwnerCopy::get('today.part_tasks', $locale, ['count' => $dueTasks]);
        }

        if ($reminders > 0) {
            $parts[] = OwnerCopy::get('today.part_reminders', $locale, ['count' => $reminders]);
        }

        if ($unread > 0) {
            $parts[] = OwnerCopy::get('today.part_notifications', $locale, ['count' => $unread]);
        }

        if ($events > 0) {
            $parts[] = OwnerCopy::get('today.part_events', $locale, ['count' => $events]);
        }

        if ($parts === []) {
            return OwnerCopy::get('today.summary_empty', $locale);
        }

        return OwnerCopy::get('today.summary_prefix', $locale, ['parts' => implode(', ', $parts)]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function itemsFrom(array $sections, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            foreach ($sections[$key] ?? [] as $row) {
                if (! is_array($row) || ($row['commitment_id'] ?? $row['source_type'] ?? '') === '') {
                    continue;
                }
                if (($row['source_type'] ?? '') !== 'commitment' && ($row['commitment_id'] ?? null) === null) {
                    continue;
                }
                $out[] = [
                    'id' => $row['commitment_id'] ?? $row['source_id'] ?? null,
                    'title' => (string) ($row['title'] ?? ''),
                    'status' => (string) ($row['type'] ?? ''),
                    'href' => $row['deep_link'] ?? null,
                    'person' => ['display_name' => $row['evidence']['person'] ?? null],
                    'summary' => (string) ($row['summary'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @return list<array<string, mixed>>
     */
    private function calendarFromBrief(array $sections): array
    {
        $events = [];
        foreach (array_merge($sections['today'] ?? [], $sections['meetings'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $events[] = [
                'id' => (string) ($row['source_id'] ?? $row['dedupe_key'] ?? $row['title'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'when_label' => (string) ($row['summary'] ?? ''),
            ];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>|null  $serialized
     */
    private function briefError(?array $serialized, OwnerLocale $locale): ?string
    {
        $errors = is_array($serialized['source_snapshot']['errors'] ?? null)
            ? $serialized['source_snapshot']['errors']
            : [];
        foreach ($errors as $error) {
            if (is_string($error) && trim($error) !== '') {
                return $error;
            }
        }

        return null;
    }
}
