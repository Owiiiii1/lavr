<?php

namespace App\Services\Workspace;

use App\Enums\OwnerLocale;
use App\Models\User;
use App\Services\Commitments\CommitmentService;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Reminders\ReminderService;
use App\Services\Reports\ScheduledReportService;
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
        $taskPanel = $this->safeTaskPanel($user);
        $reminderPanel = $this->safeReminderPanel($user);
        $inbox = $this->safeInbox($user);
        $reportPanel = $this->safeReportPanel($user);
        $calendar = $this->safeCalendar($user, $now, $locale);
        $commitments = $this->safeCommitments($user);

        $dueTasks = array_slice(array_merge(
            $taskPanel['overdue'] ?? [],
            $taskPanel['today'] ?? [],
        ), 0, 8);

        $reminders = array_slice(array_merge(
            $reminderPanel['due'] ?? [],
            $reminderPanel['today'] ?? [],
            $reminderPanel['upcoming'] ?? [],
        ), 0, 8);
        $notifications = array_slice($inbox['items'] ?? [], 0, 6);
        $reports = array_slice($reportPanel['items'] ?? [], 0, 4);

        return [
            'brand' => 'LAVR',
            'date_label' => $now->locale($locale->value)->isoFormat('dddd, D MMMM'),
            'summary' => $this->summary($locale, count($dueTasks), count($reminders), (int) ($inbox['unread_count'] ?? 0), count($calendar['events'] ?? [])),
            'tasks' => $dueTasks,
            'reminders' => $reminders,
            'notifications' => $notifications,
            'reports' => $reports,
            'calendar' => $calendar['events'],
            'calendar_hint' => $calendar['hint'],
            'calendar_error' => $calendar['error'],
            'commitments' => $commitments,
            'ask_href' => '/lavr',
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
            $account = $this->accounts->getActiveAccount($user, 'google');

            if ($account === null) {
                return [
                    'events' => [],
                    'hint' => OwnerCopy::get('today.calendar_not_connected', $locale),
                    'error' => null,
                ];
            }

            $start = $now->startOfDay();
            $end = $now->endOfDay();
            $result = $this->calendar->listEvents($account, 'primary', [
                'time_min' => $start->utc()->toIso8601String(),
                'time_max' => $end->utc()->toIso8601String(),
                'max_results' => 8,
                'order_by' => 'startTime',
                'single_events' => true,
            ]);

            $events = [];

            foreach (array_slice($result['events'] ?? [], 0, 8) as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $events[] = [
                    'id' => (string) ($event['id'] ?? ''),
                    'title' => (string) ($event['title'] ?? OwnerCopy::get('today.event_fallback', $locale)),
                    'when_label' => $this->eventWhenLabel($event, $now, $locale),
                ];
            }

            return [
                'events' => $events,
                'hint' => $events === [] ? OwnerCopy::get('today.calendar_empty', $locale) : null,
                'error' => null,
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
}
