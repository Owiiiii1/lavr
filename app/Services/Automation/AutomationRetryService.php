<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\ExecutiveBriefType;
use App\Enums\ReminderStatus;
use App\Enums\ScheduledReportStatus;
use App\Jobs\EvaluateWatcherJob;
use App\Models\AutomationRun;
use App\Models\ExecutiveBrief;
use App\Models\Reminder;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Services\ExecutiveBrief\ExecutiveBriefGenerator;
use App\Services\Reminders\ReminderDeliveryService;
use App\Services\Reports\ScheduledReportDispatchService;
use Carbon\CarbonImmutable;

final class AutomationRetryService
{
    public function __construct(
        private readonly ReminderDeliveryService $reminders,
        private readonly ScheduledReportDispatchService $reports,
        private readonly ExecutiveBriefGenerator $briefs,
    ) {}

    public function retry(AutomationRun $run): AutomationRun
    {
        $status = $run->status instanceof AutomationRunOutcome
            ? $run->status
            : AutomationRunOutcome::tryFrom((string) $run->status);

        if ($status !== AutomationRunOutcome::Failed && $status !== AutomationRunOutcome::Retryable) {
            return $run;
        }

        $run->forceFill([
            'status' => AutomationRunOutcome::Retryable,
            'safe_error' => null,
            'finished_at' => null,
        ])->save();

        $type = $run->automation_type instanceof AutomationType
            ? $run->automation_type
            : AutomationType::tryFrom((string) $run->automation_type);

        match ($type) {
            AutomationType::Reminder => $this->retryReminder($run),
            AutomationType::Watcher => EvaluateWatcherJob::dispatch((int) $run->automation_id),
            AutomationType::ScheduledReport => $this->retryReport($run),
            AutomationType::ExecutiveBrief => $this->retryBrief($run),
            default => null,
        };

        return $run->fresh() ?? $run;
    }

    private function retryReminder(AutomationRun $run): void
    {
        $reminder = Reminder::query()->find($run->automation_id);
        if ($reminder === null) {
            return;
        }

        if ($reminder->status === ReminderStatus::Delivered || $reminder->status === ReminderStatus::Completed) {
            return;
        }

        if ($reminder->status !== ReminderStatus::Processing) {
            $reminder->forceFill(['status' => ReminderStatus::Processing])->save();
        }

        $this->reminders->deliver($reminder);
    }

    private function retryReport(AutomationRun $run): void
    {
        $report = ScheduledReport::query()->with('user')->find($run->automation_id);
        $user = $report?->user;
        if ($report === null || ! $user instanceof User || $report->status !== ScheduledReportStatus::Active) {
            return;
        }

        $this->reports->run($report, $user, CarbonImmutable::now('UTC'));
    }

    private function retryBrief(AutomationRun $run): void
    {
        $user = User::query()->find($run->user_id);
        if (! $user instanceof User || ! $user->isActive()) {
            return;
        }

        $fromId = ExecutiveBrief::query()
            ->where('user_id', $user->id)
            ->where('automation_run_id', $run->id)
            ->value('id');

        $this->briefs->generate(
            $user,
            ExecutiveBriefType::Morning,
            CarbonImmutable::now('UTC'),
            'manual',
            $fromId !== null ? (int) $fromId : null,
        );
    }
}
