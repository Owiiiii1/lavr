<?php

namespace App\Services\Reports;

use App\Enums\CommitmentLifecycleStatus;
use App\Enums\ProductivityBriefMode;
use App\Enums\ScheduledReportPeriodMode;
use App\Enums\TaskStatus;
use App\Models\Commitment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\ScheduledReport;
use App\Models\Task;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\Google\GoogleGmailService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Productivity\ProductivityBriefCollector;
use App\Services\Reminders\ReminderLifecycle;
use App\Services\Tasks\TaskLifecycle;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ScheduledReportCollector
{
    public function __construct(
        private readonly ProductivityBriefCollector $briefs = new ProductivityBriefCollector,
        private readonly ?IntegrationAccountService $accounts = null,
        private readonly ?GoogleCalendarService $calendar = null,
        private readonly ?GoogleGmailService $gmail = null,
    ) {}

    /**
     * @return array{items: array<string, mixed>, errors: list<string>, window_start: string, window_end: string}
     */
    public function collect(User $user, ScheduledReport $report, CarbonImmutable $now): array
    {
        $timezone = ScheduledReportSchedule::timezoneFor($report);
        $window = $this->window($report, $now, $timezone);
        $errors = [];
        $blocked = [];
        $sourceStatuses = [];
        $items = [
            'tasks' => [],
            'reminders' => [],
            'projects' => [],
            'synthesis' => [],
            'calendar' => [],
            'gmail' => [],
            'telegram_groups' => [],
            'notifications' => [],
            'commitments' => [],
        ];

        foreach (is_array($report->sources) ? $report->sources : [] as $source) {
            if (! is_array($source)) {
                continue;
            }

            $type = (string) ($source['type'] ?? '');

            try {
                match ($type) {
                    'tasks' => $items['tasks'] = $this->tasks($user, $window, $now),
                    'reminders' => $items['reminders'] = $this->reminders($user, $window),
                    'projects' => $items['projects'] = $this->projects($user),
                    'synthesis' => $items['synthesis'] = $this->synthesis($user, $report, $now, $items),
                    'google_calendar' => $items['calendar'] = $this->calendars($user, $source, $window, $errors, $blocked),
                    'gmail' => $items['gmail'] = $this->gmail($user, $report, $window, $errors, $blocked),
                    'telegram_groups' => $items['telegram_groups'] = $this->groups($user, $window),
                    'commitments' => $items['commitments'] = $this->commitments($user),
                    'notifications' => $items['notifications'] = [],
                    default => null,
                };
                $sourceStatuses[$type] = in_array($type, $blocked, true) ? 'blocked' : 'ok';
            } catch (Throwable $exception) {
                $errors[] = $this->sourceFailure($type);
                $sourceStatuses[$type] = 'failed';
            }
        }

        return [
            'items' => $items,
            'errors' => $errors,
            'blocked' => $blocked,
            'source_statuses' => $sourceStatuses,
            'window_start' => $window['start']->toIso8601String(),
            'window_end' => $window['end']->toIso8601String(),
            'timezone' => $timezone,
            'local_date' => $window['focus']->toDateString(),
            'period_mode' => $report->period_mode->value,
            'report_type' => $report->report_type->value,
        ];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, focus: CarbonImmutable}
     */
    public function window(ScheduledReport $report, CarbonImmutable $now, string $timezone): array
    {
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        $local = $now->utc()->setTimezone($tz);

        return match ($report->period_mode) {
            ScheduledReportPeriodMode::Today => [
                'start' => $local->startOfDay()->utc(),
                'end' => $local->endOfDay()->utc(),
                'focus' => $local,
            ],
            ScheduledReportPeriodMode::Tomorrow => [
                'start' => $local->addDay()->startOfDay()->utc(),
                'end' => $local->addDay()->endOfDay()->utc(),
                'focus' => $local->addDay(),
            ],
            ScheduledReportPeriodMode::Last24h => [
                'start' => $now->utc()->subDay(),
                'end' => $now->utc(),
                'focus' => $local,
            ],
            ScheduledReportPeriodMode::SincePreviousReport => $this->sincePrevious($report, $now, $local),
        };
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, focus: CarbonImmutable}
     */
    private function sincePrevious(ScheduledReport $report, CarbonImmutable $now, CarbonImmutable $local): array
    {
        $start = $report->last_success_at instanceof CarbonImmutable
            ? $report->last_success_at->utc()
            : $now->utc()->subDay();

        return [
            'start' => $start,
            'end' => $now->utc(),
            'focus' => $local,
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, focus: CarbonImmutable}  $window
     * @return list<array{title: string, due_at: ?string, status: string, priority: string}>
     */
    private function tasks(User $user, array $window, CarbonImmutable $now): array
    {
        $rows = Task::query()
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                $query->whereIn('status', TaskLifecycle::openStatuses())
                    ->orWhere('status', TaskStatus::Completed);
            })
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->limit(80)
            ->get();

        $items = [];

        foreach ($rows as $task) {
            $open = TaskLifecycle::isOpen($task);
            $due = $task->due_at;
            $inWindow = $due instanceof CarbonImmutable
                && $due->utc()->betweenIncluded($window['start']->utc(), $window['end']->utc());
            $overdue = $open && $due instanceof CarbonImmutable && $due->utc()->lessThan($now->utc());

            if (! $inWindow && ! $overdue) {
                continue;
            }

            $items[] = [
                'title' => (string) $task->title,
                'due_at' => optional($due)?->toIso8601String(),
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'overdue' => $overdue && ! $inWindow,
            ];
        }

        return $items;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     * @return list<array{title: string, run_at: ?string}>
     */
    private function reminders(User $user, array $window): array
    {
        $rows = Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ReminderLifecycle::openStatuses())
            ->whereBetween('run_at', [$window['start']->utc(), $window['end']->utc()])
            ->orderBy('run_at')
            ->limit(20)
            ->get();

        return $rows->map(static fn (Reminder $reminder): array => [
            'title' => (string) $reminder->text,
            'run_at' => optional($reminder->run_at)?->toIso8601String(),
        ])->all();
    }

    /**
     * @return list<array{title: string, status: string}>
     */
    private function projects(User $user): array
    {
        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            return [];
        }

        return Project::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(static fn (Project $project): array => [
                'title' => (string) $project->name,
                'status' => $project->status->value,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $items
     * @return list<array{title: string}>
     */
    private function synthesis(User $user, ScheduledReport $report, CarbonImmutable $now, array $items): array
    {
        $mode = $report->period_mode === ScheduledReportPeriodMode::Tomorrow
            ? ProductivityBriefMode::Evening
            : ProductivityBriefMode::Daily;
        $tasks = Task::query()->where('user_id', $user->id)->limit(40)->get()->all();
        $reminders = Reminder::query()->where('user_id', $user->id)->limit(20)->get()->all();
        $sources = $this->briefs->collect($user, $mode, $now, $tasks, $reminders, $items['calendar'] ?? []);

        return array_map(
            static fn (array $row): array => ['title' => (string) ($row['title'] ?? '')],
            array_slice(array_merge($sources->commitments, $sources->waitingFor, $sources->synthesisAttention), 0, 8),
        );
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     * @param  list<string>  $errors
     * @param  list<string>  $blocked
     * @return list<array{title: string, start?: string, calendar?: string}>
     */
    private function calendars(User $user, array $source, array $window, array &$errors, array &$blocked): array
    {
        if (! $user->canUseCapability(UserCapability::GOOGLE_CALENDAR)) {
            $this->logSourceUnavailable('calendar', 'capability_missing');
            $errors[] = 'Календарь сейчас недоступен.';
            $blocked[] = 'google_calendar';

            return [];
        }

        if ($this->calendar === null || $this->accounts === null) {
            $this->logSourceUnavailable('calendar', 'service_unbound');
            $errors[] = 'Календарь сейчас недоступен.';

            return [];
        }

        try {
            $account = $this->accounts->getActiveAccount($user, 'google');
            if ($account === null) {
                $this->logSourceUnavailable('calendar', 'no_google_account');
                $errors[] = 'Календарь сейчас недоступен.';
                $blocked[] = 'google_calendar';

                return [];
            }

            $calendars = self::selectCalendars($this->calendar->listCalendars($account)['calendars'] ?? [], $source);
            $events = [];

            foreach ($calendars as $calendar) {
                $result = $this->calendar->listEvents($account, (string) $calendar['id'], [
                    'time_min' => $window['start']->utc()->toIso8601String(),
                    'time_max' => $window['end']->utc()->toIso8601String(),
                    'max_results' => 20,
                ]);

                foreach ($result['events'] ?? [] as $event) {
                    if (! is_array($event)) {
                        continue;
                    }

                    $events[] = [
                        'title' => (string) ($event['title'] ?? 'Событие'),
                        'start' => (string) ($event['start'] ?? ''),
                        'calendar' => (string) ($calendar['summary'] ?? $calendar['id']),
                    ];
                }
            }

            return $events;
        } catch (Throwable $exception) {
            $this->logSourceUnavailable('calendar', $this->sourceErrorReason($exception));
            $errors[] = 'Календарь сейчас недоступен.';

            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $calendars
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    public static function selectCalendars(array $calendars, array $source): array
    {
        $ids = is_array($source['calendar_ids'] ?? null) ? $source['calendar_ids'] : [];
        $names = is_array($source['calendar_names'] ?? null) ? $source['calendar_names'] : [];
        $scope = (string) ($source['calendar_scope'] ?? 'all_relevant');

        if ($ids !== []) {
            return array_values(array_filter(
                $calendars,
                static fn (array $row): bool => in_array((string) ($row['id'] ?? ''), $ids, true),
            ));
        }

        $selected = [];

        foreach ($calendars as $calendar) {
            $id = (string) ($calendar['id'] ?? '');
            $summary = mb_strtolower((string) ($calendar['summary'] ?? ''));
            $primary = (bool) ($calendar['primary'] ?? false);
            $wantedByName = $names !== [] && in_array($summary, array_map('mb_strtolower', $names), true);
            $family = str_contains($summary, 'семь') || str_contains($summary, 'family');

            if ($scope === 'all_relevant' && ($primary || $family || ($calendar['selected'] ?? false) === true)) {
                $selected[] = $calendar;
            } elseif ($wantedByName || ($ids === [] && $scope === 'selected' && $primary)) {
                $selected[] = $calendar;
            }

            unset($id);
        }

        return $selected !== [] ? $selected : array_slice($calendars, 0, 1);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     * @param  list<string>  $errors
     * @param  list<string>  $blocked
     * @return list<array{sender: string, subject: string, bucket: string, snippet: string, unread: bool, body_read: bool, action_needed: bool}>
     */
    private function gmail(User $user, ScheduledReport $report, array $window, array &$errors, array &$blocked): array
    {
        if (! $user->canUseCapability(UserCapability::GMAIL)) {
            $this->logSourceUnavailable('gmail', 'capability_missing');
            $errors[] = 'Почта сейчас недоступна.';
            $blocked[] = 'gmail';

            return [];
        }

        if ($this->gmail === null || $this->accounts === null) {
            $this->logSourceUnavailable('gmail', 'service_unbound');
            $errors[] = 'Почта сейчас недоступна.';

            return [];
        }

        try {
            $account = $this->accounts->getActiveAccount($user, 'google');
            if ($account === null) {
                $this->logSourceUnavailable('gmail', 'no_google_account');
                $errors[] = 'Почта сейчас недоступна.';
                $blocked[] = 'gmail';

                return [];
            }

            $after = $window['start']->utc()->format('Y/m/d');
            $result = $this->gmail->searchMessages($account, 'in:inbox after:'.$after, ['max_results' => 20]);
            $items = [];

            foreach ($result['messages'] ?? $result['items'] ?? [] as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $sender = MailTextNormalizer::normalize((string) ($message['from'] ?? $message['sender'] ?? ''));
                $subject = MailTextNormalizer::normalize((string) ($message['subject'] ?? $message['title'] ?? ''));
                if ($subject === '') {
                    $subject = 'без темы';
                }

                $bucket = $this->mailBucket($sender, $subject);
                $snippet = MailTextNormalizer::snippet((string) ($message['snippet'] ?? ''));

                $items[] = [
                    'sender' => $sender !== '' ? $sender : 'Неизвестный отправитель',
                    'subject' => $subject,
                    'bucket' => $bucket,
                    'snippet' => $snippet,
                    'unread' => true,
                    'body_read' => false,
                    'action_needed' => $bucket === 'important',
                ];
            }

            return $items;
        } catch (Throwable $exception) {
            $reason = $this->sourceErrorReason($exception);
            $this->logSourceUnavailable('gmail', $reason);
            $errors[] = 'Почта сейчас недоступна.';
            if (in_array($reason, ['blocked_auth', 'google_not_connected', 'gmail_scope_required'], true)) {
                $blocked[] = 'gmail';
            }

            return [];
        }
    }

    /**
     * @return list<array{title: string, status: string, person?: string}>
     */
    private function commitments(User $user): array
    {
        if (! Schema::hasTable('commitments')) {
            return [];
        }

        return Commitment::query()
            ->with('person:id,display_name')
            ->where('user_id', $user->id)
            ->whereNull('merged_into_id')
            ->where('lifecycle_status', CommitmentLifecycleStatus::Open)
            ->orderByRaw('deadline_at is null')
            ->orderBy('deadline_at')
            ->limit(20)
            ->get()
            ->map(static function (Commitment $commitment): array {
                $who = $commitment->person?->display_name ?? $commitment->person_name_raw;

                return array_filter([
                    'title' => (string) $commitment->title,
                    'status' => $commitment->status instanceof \BackedEnum ? $commitment->status->value : (string) $commitment->status,
                    'person' => is_string($who) && $who !== '' ? $who : null,
                ], static fn ($value): bool => $value !== null);
            })
            ->all();
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     * @return list<array{group: string, count: int, sample: string}>
     */
    private function groups(User $user, array $window): array
    {
        if (! $user->canUseCapability(UserCapability::TELEGRAM_GROUPS)
            && ! $user->isOwner()) {
            return [];
        }

        $groups = TelegramGroup::query()
            ->whereHas('conversation', static fn ($query) => $query->where('user_id', $user->id))
            ->limit(20)
            ->get(['id', 'title']);

        $items = [];

        foreach ($groups as $group) {
            $count = Message::query()
                ->where('telegram_group_id', $group->id)
                ->whereBetween('occurred_at', [$window['start']->utc(), $window['end']->utc()])
                ->count();

            if ($count < 1) {
                continue;
            }

            $sample = (string) (Message::query()
                ->where('telegram_group_id', $group->id)
                ->whereBetween('occurred_at', [$window['start']->utc(), $window['end']->utc()])
                ->orderByDesc('occurred_at')
                ->value('body') ?? '');

            $items[] = [
                'group' => (string) ($group->title ?: 'Группа'),
                'count' => $count,
                'sample' => MailTextNormalizer::snippet($sample, 120),
            ];
        }

        return $items;
    }

    private function mailBucket(string $sender, string $subject): string
    {
        $haystack = mb_strtolower($sender.' '.$subject);

        if (preg_match('/no[-_. ]?reply|do[-_. ]?not[-_. ]?reply|newsletter|unsubscribe|promo|рассылк|уведомлен/u', $haystack) === 1) {
            return 'noise';
        }

        if (preg_match('/urgent|срочно|invoice|счёт|action required|подтверд/u', $haystack) === 1) {
            return 'important';
        }

        return 'normal';
    }

    private function sourceFailure(string $type): string
    {
        return match ($type) {
            'google_calendar' => 'Календарь сейчас недоступен.',
            'gmail' => 'Почта сейчас недоступна.',
            'telegram_groups' => 'Сводка по группам сейчас недоступна.',
            default => 'Часть источников сейчас недоступна.',
        };
    }

    private function sourceErrorReason(Throwable $exception): string
    {
        return $exception instanceof IntegrationException
            ? $exception->error
            : $exception::class;
    }

    private function logSourceUnavailable(string $source, string $reason): void
    {
        Log::info('scheduled_report.source_unavailable', [
            'source' => $source,
            'reason' => $reason,
        ]);
    }
}
