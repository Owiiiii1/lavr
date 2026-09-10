<?php

namespace App\Services\Reports;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\JarvisNotificationType;
use App\Enums\ScheduledReportRunStatus;
use App\Enums\ScheduledReportStatus;
use App\Models\ChannelIdentity;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
use App\Services\Automation\AutomationEvent;
use App\Services\Automation\AutomationEventBus;
use App\Services\Automation\AutomationExecutor;
use App\Services\Automation\AutomationResult;
use App\Services\Automation\AutomationRunKey;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ScheduledReportDispatchService
{
    public function __construct(
        private readonly ScheduledReportCollector $collector,
        private readonly ScheduledReportComposer $composer,
        private readonly JarvisNotificationService $inbox,
        private readonly NotificationUrlPolicy $urls,
        private readonly ?SendsReminderTelegram $telegram = null,
        private readonly AutomationExecutor $executor = new AutomationExecutor,
        private readonly AutomationEventBus $events = new AutomationEventBus,
    ) {}

    public function dispatchDue(int $limit = 40): int
    {
        if (! Schema::hasTable('scheduled_reports')) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $sent = 0;
        $reports = ScheduledReport::query()
            ->with('user')
            ->where('status', ScheduledReportStatus::Active)
            ->where('next_run_at', '<=', $now)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($reports as $report) {
            $user = $report->user;
            if (! $user instanceof User || ! $user->isActive()) {
                continue;
            }

            if ($this->run($report, $user, $now) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    public function run(ScheduledReport $report, User $user, CarbonImmutable $now): ?ScheduledReportRun
    {
        $timezone = ScheduledReportSchedule::timezoneFor($report);
        $slot = ScheduledReportSchedule::slotKey($now, $timezone, (string) $report->local_time);
        $runKey = AutomationRunKey::scheduledReport((int) $report->id, $slot);
        $slotRun = null;
        $duplicate = false;

        $this->executor->run(
            $user,
            AutomationType::ScheduledReport,
            (int) $report->id,
            $runKey,
            function () use ($report, $user, $now, $timezone, $slot, &$slotRun, &$duplicate): AutomationResult {
                $started = hrtime(true);
                $slotRun = $this->claimSlot($report, $user, $slot, $now);
                if ($slotRun === null) {
                    $duplicate = true;

                    return AutomationResult::of(AutomationRunOutcome::Skipped, 'duplicate_slot', 'already recorded');
                }

                if (in_array($slotRun->status, [ScheduledReportRunStatus::Succeeded, ScheduledReportRunStatus::Partial], true)
                    && $slotRun->notification_id !== null) {
                    $duplicate = true;

                    return AutomationResult::of(
                        $slotRun->status === ScheduledReportRunStatus::Partial
                            ? AutomationRunOutcome::Partial
                            : AutomationRunOutcome::Success,
                        'already_delivered',
                        '',
                        0,
                        [],
                        'deduped',
                    );
                }

                try {
                    $collected = $this->collector->collect($user, $report, $now);
                    $errors = is_array($collected['errors'] ?? null) ? $collected['errors'] : [];
                    $blocked = is_array($collected['blocked'] ?? null) ? $collected['blocked'] : [];
                    $items = is_array($collected['items'] ?? null) ? $collected['items'] : [];
                    $itemCount = $this->countItems($items);
                    $delivery = is_array($report->delivery) ? $report->delivery : [];
                    $skipIfEmpty = (bool) ($delivery['skip_if_empty'] ?? false);

                    if ($skipIfEmpty && $itemCount === 0 && $errors === []) {
                        $this->finishSlot($slotRun, $report, $timezone, ScheduledReportRunStatus::Succeeded, $collected, '', null, $errors, skipped: true);

                        return AutomationResult::of(AutomationRunOutcome::Skipped, 'empty', 'skip_if_empty', (int) ((hrtime(true) - $started) / 1_000_000), [
                            'sources_attempted' => count(is_array($report->sources) ? $report->sources : []),
                            'items_collected' => 0,
                            'prompt_version' => config('automation.prompt_version'),
                        ], 'skipped');
                    }

                    $composed = $this->composer->compose($user, $report, $collected);
                    $status = $errors === [] ? ScheduledReportRunStatus::Succeeded : ScheduledReportRunStatus::Partial;
                } catch (Throwable $exception) {
                    $slotRun->forceFill([
                        'status' => ScheduledReportRunStatus::Failed,
                        'error_code' => 'compose_failed',
                        'finished_at' => CarbonImmutable::now('UTC'),
                    ])->save();
                    $report->forceFill([
                        'last_run_at' => CarbonImmutable::now('UTC'),
                        'last_error_code' => 'compose_failed',
                        'next_run_at' => ScheduledReportSchedule::nextDailyLocal(
                            CarbonImmutable::now('UTC'),
                            $timezone,
                            (string) $report->local_time,
                            allowToday: false,
                        ),
                    ])->save();

                    throw $exception;
                }
                $safeCollected = $this->boundedCollected($collected);
                $deliveryStarted = hrtime(true);

                $notification = $slotRun->notification_id !== null
                    ? null
                    : $this->inbox->record(
                        $user,
                        JarvisNotificationType::ScheduledReportReady,
                        (string) $report->name,
                        $composed['text'],
                        'scheduled_report:'.$report->id.':'.$slot,
                        'scheduled_report',
                        (int) $report->id,
                        $this->urls->workspacePath($user, 'reports=1'),
                        [
                            'scheduled_report_id' => (int) $report->id,
                            'slot_key' => $slot,
                            'ai_phrased' => $composed['ai_used'],
                            'source_errors' => $errors,
                            'prompt_version' => config('automation.prompt_version'),
                        ],
                        $composed['ai_used'],
                    );

                if ($slotRun->notification_id === null) {
                    $this->maybeTelegram($user, $report, $composed['text']);
                }

                $this->finishSlot(
                    $slotRun,
                    $report,
                    $timezone,
                    $status,
                    $safeCollected,
                    $composed['text'],
                    $notification?->id ?? $slotRun->notification_id,
                    $errors,
                    blocked: $blocked !== [],
                );

                $this->events->emit(new AutomationEvent('report.completed', (int) $user->id, (int) $report->id));

                $sourcesAttempted = count(is_array($report->sources) ? $report->sources : []);
                $sourcesFailed = count($errors);
                $outcome = $errors === [] ? AutomationRunOutcome::Success : AutomationRunOutcome::Partial;

                return AutomationResult::of(
                    $outcome,
                    $blocked !== [] ? 'partial_source' : ($errors === [] ? 'delivered' : 'partial_source'),
                    '',
                    (int) ((hrtime(true) - $started) / 1_000_000),
                    [
                        'sources_attempted' => $sourcesAttempted,
                        'sources_succeeded' => max(0, $sourcesAttempted - $sourcesFailed),
                        'sources_failed' => $sourcesFailed,
                        'items_collected' => $itemCount,
                        'items_rendered' => $itemCount,
                        'delivery_latency_ms' => (int) ((hrtime(true) - $deliveryStarted) / 1_000_000),
                        'prompt_version' => (string) config('automation.prompt_version'),
                        'ai_used' => (bool) $composed['ai_used'],
                    ],
                    $notification !== null || $slotRun->notification_id !== null ? 'success' : 'success',
                );
            },
            $now,
            $runKey,
        );

        return $slotRun;
    }

    private function claimSlot(ScheduledReport $report, User $user, string $slot, CarbonImmutable $now): ?ScheduledReportRun
    {
        try {
            return ScheduledReportRun::query()->create([
                'scheduled_report_id' => $report->id,
                'user_id' => $user->id,
                'slot_key' => $slot,
                'status' => ScheduledReportRunStatus::Running,
                'started_at' => $now->utc(),
            ]);
        } catch (Throwable) {
            $existing = ScheduledReportRun::query()
                ->where('scheduled_report_id', $report->id)
                ->where('slot_key', $slot)
                ->first();

            if ($existing === null) {
                return null;
            }

            if (in_array($existing->status, [ScheduledReportRunStatus::Succeeded, ScheduledReportRunStatus::Partial], true)) {
                return $existing;
            }

            if ($existing->status === ScheduledReportRunStatus::Running) {
                return null;
            }

            $existing->forceFill([
                'status' => ScheduledReportRunStatus::Running,
                'started_at' => $now->utc(),
                'finished_at' => null,
                'error_code' => null,
            ])->save();

            return $existing;
        }
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  list<string>  $errors
     */
    private function finishSlot(
        ScheduledReportRun $run,
        ScheduledReport $report,
        string $timezone,
        ScheduledReportRunStatus $status,
        array $collected,
        string $body,
        ?int $notificationId,
        array $errors,
        bool $skipped = false,
        bool $blocked = false,
    ): void {
        $run->forceFill([
            'status' => $status,
            'collected' => $this->boundedCollected($collected),
            'body' => $body !== '' ? $body : null,
            'notification_id' => $notificationId,
            'source_errors' => $errors,
            'error_code' => $skipped ? 'skipped_empty' : ($errors === [] ? null : 'partial_source'),
            'finished_at' => CarbonImmutable::now('UTC'),
        ])->save();

        $errorCode = $blocked
            ? 'blocked_auth'
            : ($errors === [] ? null : 'partial_source');

        $report->forceFill([
            'last_run_at' => CarbonImmutable::now('UTC'),
            'last_success_at' => $skipped ? $report->last_success_at : CarbonImmutable::now('UTC'),
            'last_error_code' => $errorCode,
            'next_run_at' => ScheduledReportSchedule::nextDailyLocal(
                CarbonImmutable::now('UTC'),
                $timezone,
                (string) $report->local_time,
                allowToday: false,
            ),
            'cursor' => array_merge(is_array($report->cursor) ? $report->cursor : [], [
                'baseline_established' => true,
                'last_slot' => $run->slot_key,
            ]),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $items
     */
    private function countItems(array $items): int
    {
        $count = 0;
        foreach ($items as $group) {
            if (is_array($group)) {
                $count += count($group);
            }
        }

        return $count;
    }

    private function maybeTelegram(User $user, ScheduledReport $report, string $text): void
    {
        $delivery = is_array($report->delivery) ? $report->delivery : [];
        if (($delivery['telegram'] ?? true) !== true || $this->telegram === null) {
            return;
        }

        $identity = ChannelIdentity::findTelegramForUser((int) $user->id);
        $chatId = ($identity !== null && filled($identity->external_chat_id))
            ? (string) $identity->external_chat_id
            : null;

        if ($chatId === null) {
            return;
        }

        try {
            $this->telegram->send($chatId, $text, 'reports');
        } catch (Throwable) {
        }
    }

    /**
     * @param  array<string, mixed>  $collected
     * @return array<string, mixed>
     */
    private function boundedCollected(array $collected): array
    {
        $items = is_array($collected['items'] ?? null) ? $collected['items'] : [];
        foreach (['gmail'] as $key) {
            if (! isset($items[$key]) || ! is_array($items[$key])) {
                continue;
            }

            $items[$key] = array_map(static function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                unset($row['body'], $row['snippet'], $row['text']);

                return $row;
            }, $items[$key]);
        }

        return [
            'errors' => $collected['errors'] ?? [],
            'local_date' => $collected['local_date'] ?? null,
            'period_mode' => $collected['period_mode'] ?? null,
            'items' => $items,
        ];
    }
}
