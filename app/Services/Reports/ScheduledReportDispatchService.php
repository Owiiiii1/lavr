<?php

namespace App\Services\Reports;

use App\Enums\JarvisNotificationType;
use App\Enums\ScheduledReportRunStatus;
use App\Enums\ScheduledReportStatus;
use App\Models\ChannelIdentity;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
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

        try {
            $run = ScheduledReportRun::query()->create([
                'scheduled_report_id' => $report->id,
                'user_id' => $user->id,
                'slot_key' => $slot,
                'status' => ScheduledReportRunStatus::Running,
                'started_at' => $now->utc(),
            ]);
        } catch (Throwable) {
            return null;
        }

        try {
            $collected = $this->collector->collect($user, $report, $now);
            $composed = $this->composer->compose($user, $report, $collected);
            $errors = is_array($collected['errors'] ?? null) ? $collected['errors'] : [];
            $status = $errors === [] ? ScheduledReportRunStatus::Succeeded : ScheduledReportRunStatus::Partial;
            $safeCollected = $this->boundedCollected($collected);

            $notification = $this->inbox->record(
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
                ],
                $composed['ai_used'],
            );

            $this->maybeTelegram($user, $report, $composed['text']);

            $run->forceFill([
                'status' => $status,
                'collected' => $safeCollected,
                'body' => $composed['text'],
                'notification_id' => $notification?->id,
                'source_errors' => $errors,
                'finished_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $report->forceFill([
                'last_run_at' => CarbonImmutable::now('UTC'),
                'last_success_at' => CarbonImmutable::now('UTC'),
                'last_error_code' => $errors === [] ? null : 'partial_source',
                'next_run_at' => ScheduledReportSchedule::nextDailyLocal(
                    CarbonImmutable::now('UTC'),
                    $timezone,
                    (string) $report->local_time,
                    allowToday: false,
                ),
                'cursor' => array_merge(is_array($report->cursor) ? $report->cursor : [], [
                    'baseline_established' => true,
                    'last_slot' => $slot,
                ]),
            ])->save();

            return $run->fresh() ?? $run;
        } catch (Throwable $exception) {
            $run->forceFill([
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

            return $run->fresh() ?? $run;
        }
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
