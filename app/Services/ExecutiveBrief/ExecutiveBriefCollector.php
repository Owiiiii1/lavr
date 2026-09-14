<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\AutomationRunOutcome;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\ExecutiveBriefPriority;
use App\Enums\ExecutiveBriefType;
use App\Enums\IntegrationAccountStatus;
use App\Enums\MeetingAnalysisStatus;
use App\Enums\MeetingStatus;
use App\Models\AutomationRun;
use App\Models\Commitment;
use App\Models\IntegrationAccount;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\LeadershipReview\LeadershipSignalDetector;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ExecutiveBriefCollector
{
    public function __construct(
        private readonly ?IntegrationAccountService $accounts = null,
        private readonly ?object $calendar = null,
        private readonly ?object $gmail = null,
        private readonly ?LeadershipSignalDetector $leadership = null,
    ) {}

    /**
     * @return array{items: list<ExecutiveBriefItem>, snapshot: array<string, mixed>}
     */
    public function collect(User $user, ExecutiveBriefType $type, CarbonImmutable $now, string $timezone): array
    {
        $local = $now->utc()->setTimezone($timezone);
        $windowStart = $local->startOfDay()->subDay()->utc();
        $windowEnd = $local->endOfDay()->utc();
        $items = [];
        $attempted = 0;
        $succeeded = 0;
        $failed = 0;
        $blocked = [];
        $errors = [];
        $freshness = [];

        $sources = [
            'commitments' => fn () => $this->commitments($user, $local),
            'meetings' => fn () => $this->meetings($user, $local),
            'calendar' => function () use ($user, $local, &$errors, &$blocked): array {
                return $this->calendar($user, $local, $errors, $blocked);
            },
            'gmail' => function () use ($user, $windowStart, &$errors, &$blocked): array {
                return $this->gmail($user, $windowStart, $errors, $blocked);
            },
            'integrations' => fn () => $this->integrations($user),
            'automations' => fn () => $this->automationFailures($user, $windowStart),
            'leadership' => fn () => ($this->leadership ?? new LeadershipSignalDetector)->items($user),
        ];

        foreach ($sources as $name => $loader) {
            $attempted++;
            try {
                $batch = $loader();
                $items = array_merge($items, $batch);
                $succeeded++;
                $freshness[$name] = 'ok';
            } catch (Throwable $exception) {
                $failed++;
                $freshness[$name] = 'failed';
                $errors[] = $this->sourceLabel($name);
                Log::info('executive brief source failed', [
                    'source' => $name,
                    'error_class' => $exception::class,
                ]);
            }
        }

        return [
            'items' => $items,
            'snapshot' => [
                'sources_attempted' => $attempted,
                'sources_succeeded' => $succeeded,
                'sources_failed' => $failed,
                'items_collected' => count($items),
                'blocked' => array_values(array_unique($blocked)),
                'errors' => $errors,
                'freshness' => $freshness,
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $windowEnd->toIso8601String(),
                'timezone' => $timezone,
                'brief_type' => $type->value,
            ],
        ];
    }

    /**
     * @return list<ExecutiveBriefItem>
     */
    private function commitments(User $user, CarbonImmutable $local): array
    {
        if (! Schema::hasTable('commitments')) {
            return [];
        }

        $todayStart = $local->startOfDay()->utc();
        $todayEnd = $local->endOfDay()->utc();
        $soonEnd = $local->addHours(48)->utc();

        $rows = Commitment::query()
            ->with(['person:id,display_name', 'project:id,name'])
            ->where('user_id', $user->id)
            ->whereNull('merged_into_id')
            ->whereIn('lifecycle_status', [
                CommitmentLifecycleStatus::Open,
                CommitmentLifecycleStatus::LikelyDone,
                CommitmentLifecycleStatus::Detected,
            ])
            ->orderBy('deadline_at')
            ->limit(40)
            ->get();

        $items = [];
        foreach ($rows as $commitment) {
            $status = $commitment->status instanceof CommitmentEffectiveStatus
                ? $commitment->status
                : CommitmentEffectiveStatus::tryFrom((string) $commitment->status);
            if ($status === null) {
                continue;
            }

            $deadline = $this->asUtc($commitment->deadline_at);
            $dueToday = $deadline !== null && $deadline->betweenIncluded($todayStart, $todayEnd);
            $who = $commitment->person?->display_name ?? $commitment->person_name_raw;
            $who = is_string($who) ? trim($who) : '';

            if ($status === CommitmentEffectiveStatus::Confirmed) {
                continue;
            }

            $item = $this->commitmentItem($commitment, $status, $dueToday, $who, $deadline, $soonEnd);
            if ($item !== null && $who !== '') {
                $item = $item->withEvidencePerson($who);
            }
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function commitmentItem(
        Commitment $commitment,
        CommitmentEffectiveStatus $status,
        bool $dueToday,
        string $who,
        ?CarbonImmutable $deadline,
        CarbonImmutable $soonEnd,
    ): ?ExecutiveBriefItem {
        $title = (string) $commitment->title;
        $link = '/lavr/commitments/'.$commitment->id;
        $dueAt = $deadline?->toIso8601String();

        if ($status === CommitmentEffectiveStatus::LikelyDone) {
            return new ExecutiveBriefItem(
                type: 'commitment_likely_done',
                priority: ExecutiveBriefPriority::Normal,
                title: $title,
                summary: 'Ймовірно виконано, потрібне підтвердження: '.$title,
                section: 'commitments',
                dedupeKey: 'commitment:'.$commitment->id,
                confidence: 'medium',
                score: 48,
                actionLabel: 'Підтвердити?',
                sourceType: 'commitment',
                sourceId: (int) $commitment->id,
                personId: $commitment->person_id !== null ? (int) $commitment->person_id : null,
                projectId: $commitment->project_id !== null ? (int) $commitment->project_id : null,
                commitmentId: (int) $commitment->id,
                dueAt: $dueAt,
                deepLink: $link,
            );
        }

        if ($status === CommitmentEffectiveStatus::Overdue) {
            $days = $deadline !== null ? max(1, (int) $deadline->diffInDays(CarbonImmutable::now('UTC'), true)) : 1;
            $summary = $who !== ''
                ? $who.' прострочив «'.$title.'» на '.$days.' дн.'
                : 'Прострочено: '.$title;

            return new ExecutiveBriefItem(
                type: 'commitment_overdue',
                priority: ExecutiveBriefPriority::Critical,
                title: $title,
                summary: $summary,
                section: 'overdue',
                dedupeKey: 'commitment:'.$commitment->id,
                confidence: 'high',
                score: 92,
                actionLabel: $who !== '' ? 'Нагадати?' : null,
                sourceType: 'commitment',
                sourceId: (int) $commitment->id,
                personId: $commitment->person_id !== null ? (int) $commitment->person_id : null,
                projectId: $commitment->project_id !== null ? (int) $commitment->project_id : null,
                meetingId: $commitment->meeting_id !== null ? (int) $commitment->meeting_id : null,
                commitmentId: (int) $commitment->id,
                dueAt: $dueAt,
                deepLink: $link,
            );
        }

        if ($dueToday || $status === CommitmentEffectiveStatus::DueSoon) {
            $priority = $dueToday ? ExecutiveBriefPriority::High : ExecutiveBriefPriority::Normal;
            $summary = $dueToday
                ? ($who !== '' ? $who.' має здати сьогодні: '.$title : 'Сьогодні: '.$title)
                : ($who !== '' ? $who.' — найближчий дедлайн: '.$title : 'Найближчий дедлайн: '.$title);

            return new ExecutiveBriefItem(
                type: $dueToday ? 'commitment_due_today' : 'commitment_due_soon',
                priority: $priority,
                title: $title,
                summary: $summary,
                section: 'commitments',
                dedupeKey: 'commitment:'.$commitment->id,
                confidence: 'high',
                score: $dueToday ? 78 : 58,
                sourceType: 'commitment',
                sourceId: (int) $commitment->id,
                personId: $commitment->person_id !== null ? (int) $commitment->person_id : null,
                projectId: $commitment->project_id !== null ? (int) $commitment->project_id : null,
                meetingId: $commitment->meeting_id !== null ? (int) $commitment->meeting_id : null,
                commitmentId: (int) $commitment->id,
                dueAt: $dueAt,
                deepLink: $link,
            );
        }

        if ($status === CommitmentEffectiveStatus::Detected) {
            return new ExecutiveBriefItem(
                type: 'commitment_detected',
                priority: ExecutiveBriefPriority::High,
                title: $title,
                summary: 'Виявлене зобов’язання потребує підтвердження: '.$title,
                section: 'decisions',
                dedupeKey: 'commitment:'.$commitment->id,
                confidence: 'medium',
                score: 70,
                actionLabel: 'Підтвердити?',
                sourceType: 'commitment',
                sourceId: (int) $commitment->id,
                personId: $commitment->person_id !== null ? (int) $commitment->person_id : null,
                projectId: $commitment->project_id !== null ? (int) $commitment->project_id : null,
                meetingId: $commitment->meeting_id !== null ? (int) $commitment->meeting_id : null,
                commitmentId: (int) $commitment->id,
                dueAt: $dueAt,
                deepLink: $link,
            );
        }

        if ($deadline !== null && $deadline->lessThanOrEqualTo($soonEnd)) {
            return new ExecutiveBriefItem(
                type: 'commitment_open',
                priority: ExecutiveBriefPriority::Low,
                title: $title,
                summary: $title,
                section: 'commitments',
                dedupeKey: 'commitment:'.$commitment->id,
                confidence: 'high',
                score: 28,
                sourceType: 'commitment',
                sourceId: (int) $commitment->id,
                commitmentId: (int) $commitment->id,
                dueAt: $dueAt,
                deepLink: $link,
            );
        }

        return null;
    }

    /**
     * @return list<ExecutiveBriefItem>
     */
    private function meetings(User $user, CarbonImmutable $local): array
    {
        if (! Schema::hasTable('meetings')) {
            return [];
        }

        $todayStart = $local->startOfDay()->utc();
        $todayEnd = $local->endOfDay()->utc();
        $lookback = $local->startOfDay()->subDays(3)->utc();

        $rows = Meeting::query()
            ->with(['currentAnalysis', 'project:id,name'])
            ->where('user_id', $user->id)
            ->where('status', '!=', MeetingStatus::Archived)
            ->where(function ($query) use ($todayStart, $todayEnd, $lookback): void {
                $query->whereBetween('started_at', [$todayStart, $todayEnd])
                    ->orWhere(function ($inner) use ($lookback, $todayStart): void {
                        $inner->whereBetween('started_at', [$lookback, $todayStart]);
                    });
            })
            ->orderBy('started_at')
            ->limit(20)
            ->get();

        $items = [];
        $now = CarbonImmutable::now('UTC');
        foreach ($rows as $meeting) {
            $start = $this->asUtc($meeting->started_at);
            $isToday = $start !== null && $start->betweenIncluded($todayStart, $todayEnd);
            $hours = $start !== null ? $now->diffInHours($start, false) : null;
            $analysis = $meeting->currentAnalysis;
            $result = is_array($analysis?->result_json) ? $analysis->result_json : [];
            $risks = is_array($result['risks'] ?? null) ? $result['risks'] : [];
            $decisions = is_array($result['decisions'] ?? null) ? $result['decisions'] : [];
            $actions = is_array($result['action_items'] ?? null) ? $result['action_items'] : [];
            $hasRisk = $risks !== [] && ($analysis?->status === MeetingAnalysisStatus::Completed);
            $hasOpenWork = $decisions !== [] || $actions !== [];

            if ($isToday) {
                $soon = is_numeric($hours) && $hours >= 0 && $hours <= 3;
                $items[] = new ExecutiveBriefItem(
                    type: 'meeting_today',
                    priority: $soon || $hasRisk ? ExecutiveBriefPriority::High : ExecutiveBriefPriority::Normal,
                    title: (string) $meeting->title,
                    summary: $this->meetingTodaySummary($meeting, $start, $local, $hasRisk),
                    section: 'meetings',
                    dedupeKey: 'meeting:'.$meeting->id,
                    confidence: 'high',
                    score: $hasRisk ? 82 : ($soon ? 74 : 52),
                    sourceType: 'meeting',
                    sourceId: (int) $meeting->id,
                    projectId: $meeting->project_id !== null ? (int) $meeting->project_id : null,
                    meetingId: (int) $meeting->id,
                    dueAt: $start?->toIso8601String(),
                    deepLink: '/lavr/meetings/'.$meeting->id,
                );
            }

            if (! $isToday && ($hasRisk || $hasOpenWork)) {
                $riskText = $this->firstRiskText($risks);
                $items[] = new ExecutiveBriefItem(
                    type: $hasRisk ? 'meeting_risk' : 'meeting_followup',
                    priority: $hasRisk ? ExecutiveBriefPriority::High : ExecutiveBriefPriority::Normal,
                    title: (string) $meeting->title,
                    summary: $hasRisk
                        ? 'Після зустрічі «'.$meeting->title.'»: '.$riskText
                        : 'Після зустрічі залишились відкриті дії: '.$meeting->title,
                    section: $hasRisk ? 'risks' : 'followups',
                    dedupeKey: 'meeting:'.$meeting->id.':aftermath',
                    confidence: 'medium',
                    score: $hasRisk ? 80 : 44,
                    sourceType: 'meeting',
                    sourceId: (int) $meeting->id,
                    projectId: $meeting->project_id !== null ? (int) $meeting->project_id : null,
                    meetingId: (int) $meeting->id,
                    deepLink: '/lavr/meetings/'.$meeting->id,
                    evidence: ['risks' => count($risks), 'decisions' => count($decisions), 'actions' => count($actions)],
                );
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $risks
     */
    private function firstRiskText(array $risks): string
    {
        foreach ($risks as $risk) {
            if (is_array($risk) && trim((string) ($risk['text'] ?? '')) !== '') {
                return mb_substr(trim((string) $risk['text']), 0, 180);
            }
        }

        return 'є ризик, який потребує уваги';
    }

    private function meetingTodaySummary(Meeting $meeting, ?CarbonImmutable $start, CarbonImmutable $local, bool $hasRisk): string
    {
        $when = $start !== null ? $start->setTimezone($local->getTimezone())->format('H:i') : '';
        $project = $meeting->project?->name;
        $bits = array_filter([$when, is_string($project) ? $project : null]);
        $prefix = $bits !== [] ? implode(' · ', $bits).' · ' : '';

        return $hasRisk
            ? $prefix.'зустріч з ризиком: '.$meeting->title
            : $prefix.(string) $meeting->title;
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $blocked
     * @return list<ExecutiveBriefItem>
     */
    private function calendar(User $user, CarbonImmutable $local, array &$errors, array &$blocked): array
    {
        if (! $user->canUseCapability(UserCapability::GOOGLE_CALENDAR) || $this->calendar === null || $this->accounts === null) {
            return [];
        }

        try {
            $account = $this->accounts->getActiveAccount($user, 'google');
        } catch (Throwable) {
            $account = null;
        }

        if ($account === null) {
            return [];
        }

        try {
            $result = $this->calendar->listEvents($account, 'primary', [
                'time_min' => $local->startOfDay()->utc()->toIso8601String(),
                'time_max' => $local->endOfDay()->utc()->toIso8601String(),
                'max_results' => 12,
                'order_by' => 'startTime',
                'single_events' => true,
            ]);
        } catch (Throwable $exception) {
            $errors[] = 'Календар тимчасово недоступний.';
            if ($exception instanceof IntegrationException && in_array($exception->error, ['blocked_auth', 'google_not_connected'], true)) {
                $blocked[] = 'google_calendar';
            }

            return [];
        }

        $items = [];
        $now = CarbonImmutable::now('UTC');
        foreach (array_slice($result['events'] ?? [], 0, 12) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $title = trim((string) ($event['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $startRaw = (string) ($event['start'] ?? '');
            $start = null;
            try {
                $start = $startRaw !== '' ? CarbonImmutable::parse($startRaw)->utc() : null;
            } catch (Throwable) {
                $start = null;
            }
            $hours = $start !== null ? $now->diffInHours($start, false) : null;
            $soon = is_numeric($hours) && $hours >= 0 && $hours <= 2;
            $id = (string) ($event['id'] ?? $title);

            $items[] = new ExecutiveBriefItem(
                type: 'calendar_event',
                priority: $soon ? ExecutiveBriefPriority::High : ExecutiveBriefPriority::Normal,
                title: $title,
                summary: ($start !== null ? $start->setTimezone($local->getTimezone())->format('H:i').' · ' : '').$title,
                section: 'today',
                dedupeKey: 'calendar:'.$id,
                confidence: 'high',
                score: $soon ? 72 : 40,
                sourceType: 'calendar',
                dueAt: $start?->toIso8601String(),
                deepLink: '/lavr/today',
                evidence: ['calendar' => (string) ($event['calendar'] ?? '')],
            );
        }

        return $items;
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $blocked
     * @return list<ExecutiveBriefItem>
     */
    private function gmail(User $user, CarbonImmutable $windowStart, array &$errors, array &$blocked): array
    {
        if (! $user->canUseCapability(UserCapability::GMAIL) || $this->gmail === null || $this->accounts === null) {
            return [];
        }

        try {
            $account = $this->accounts->getActiveAccount($user, 'google');
        } catch (Throwable) {
            $account = null;
        }

        if ($account === null) {
            return [];
        }

        try {
            $after = $windowStart->utc()->format('Y/m/d');
            $result = $this->gmail->searchMessages($account, 'in:inbox after:'.$after, ['max_results' => (int) config('executive_brief.gmail_query_limit', 12)]);
        } catch (Throwable $exception) {
            $errors[] = 'Пошта тимчасово недоступна.';
            if ($exception instanceof IntegrationException && in_array($exception->error, ['blocked_auth', 'google_not_connected', 'gmail_scope_required'], true)) {
                $blocked[] = 'gmail';
            }

            return [];
        }

        $messages = is_array($result['messages'] ?? null) ? $result['messages'] : (is_array($result) ? $result : []);
        $items = [];
        foreach (array_slice($messages, 0, 12) as $message) {
            if (! is_array($message)) {
                continue;
            }
            $sender = trim((string) ($message['from'] ?? $message['sender'] ?? ''));
            $subject = trim((string) ($message['subject'] ?? ''));
            if ($subject === '') {
                continue;
            }
            $haystack = mb_strtolower($sender.' '.$subject.' '.(string) ($message['snippet'] ?? ''));
            if (preg_match('/no[-_. ]?reply|newsletter|unsubscribe|promo/u', $haystack) === 1) {
                continue;
            }
            $important = preg_match('/urgent|срочно|invoice|счёт|action required|підтверд|підтверд|deadline|бюджет/u', $haystack) === 1;
            $snippet = mb_substr(trim((string) ($message['snippet'] ?? '')), 0, 140);
            $bodyRead = filled($message['body'] ?? $message['text'] ?? null);
            $id = (string) ($message['id'] ?? hash('sha256', $sender.'|'.$subject));

            $items[] = new ExecutiveBriefItem(
                type: $important ? 'email_important' : 'email_actionable',
                priority: $important ? ExecutiveBriefPriority::High : ExecutiveBriefPriority::Normal,
                title: $subject,
                summary: ($sender !== '' ? $sender.' — ' : '').($snippet !== '' ? $snippet : $subject),
                section: 'inbox',
                dedupeKey: 'email:'.mb_strtolower($sender).':'.mb_strtolower($subject),
                confidence: $bodyRead ? 'medium' : 'low',
                score: $important ? 68 : 42,
                sourceType: 'gmail',
                sourceId: is_numeric($id) ? (int) $id : null,
                deepLink: '/lavr/today',
                evidence: ['sender' => $sender, 'body_unavailable' => ! $bodyRead],
                bodyRead: $bodyRead,
            );
        }

        return $items;
    }

    /**
     * @return list<ExecutiveBriefItem>
     */
    private function integrations(User $user): array
    {
        if (! Schema::hasTable('integration_accounts')) {
            return [];
        }

        $rows = IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [IntegrationAccountStatus::Revoked, IntegrationAccountStatus::Error])
            ->limit(8)
            ->get();

        $items = [];
        foreach ($rows as $account) {
            $provider = (string) $account->provider;
            $blockedAuth = $account->status === IntegrationAccountStatus::Revoked
                || (string) ($account->last_error_code ?? '') === 'blocked_auth';
            if (! $blockedAuth && $account->status !== IntegrationAccountStatus::Error) {
                continue;
            }

            $label = match ($provider) {
                'google' => 'Gmail / Calendar',
                'zoom' => 'Zoom',
                'github' => 'GitHub',
                default => $provider,
            };

            $items[] = new ExecutiveBriefItem(
                type: 'integration_blocked',
                priority: ExecutiveBriefPriority::Critical,
                title: 'Потрібно перепідключити '.$label,
                summary: $label.' потребує повторного підключення.',
                section: 'attention',
                dedupeKey: 'integration:'.$provider.':blocked',
                confidence: 'high',
                score: 88,
                actionLabel: 'Відкрити налаштування',
                sourceType: 'integration',
                sourceId: (int) $account->id,
                deepLink: '/lavr?settings=profile',
            );
        }

        return $items;
    }

    /**
     * @return list<ExecutiveBriefItem>
     */
    private function automationFailures(User $user, CarbonImmutable $since): array
    {
        if (! Schema::hasTable('automation_runs')) {
            return [];
        }

        $count = AutomationRun::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [AutomationRunOutcome::Failed, AutomationRunOutcome::Retryable])
            ->where('started_at', '>=', $since)
            ->count();

        if ($count < 3) {
            return [];
        }

        return [
            new ExecutiveBriefItem(
                type: 'automation_failures',
                priority: ExecutiveBriefPriority::High,
                title: 'Автоматизації повторно падають',
                summary: 'За останню добу кілька автоматизацій завершились з помилкою.',
                section: 'risks',
                dedupeKey: 'automation:repeat-failures',
                confidence: 'high',
                score: 64,
                sourceType: 'automation_run',
                deepLink: '/lavr/more',
                evidence: ['failed_runs' => $count],
            ),
        ];
    }

    private function sourceLabel(string $name): string
    {
        return match ($name) {
            'calendar' => 'Календар тимчасово недоступний.',
            'gmail' => 'Пошта тимчасово недоступна.',
            default => '',
        };
    }

    private function asUtc(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return null;
    }
}
