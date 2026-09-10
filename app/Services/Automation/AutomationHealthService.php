<?php

namespace App\Services\Automation;

use App\Enums\AutomationHealth;
use App\Enums\ReminderStatus;
use App\Enums\ScheduledReportStatus;
use App\Enums\WatcherHealth;
use App\Enums\WatcherStatus;
use App\Models\Reminder;
use App\Models\ScheduledReport;
use App\Models\Watcher;

final class AutomationHealthService
{
    public function forWatcher(Watcher $watcher): AutomationHealth
    {
        if (in_array($watcher->status, [WatcherStatus::Paused, WatcherStatus::Cancelled, WatcherStatus::Completed, WatcherStatus::Failed], true)) {
            return $watcher->status === WatcherStatus::Failed ? AutomationHealth::Degraded : AutomationHealth::Disabled;
        }

        if ($watcher->health === WatcherHealth::Blocked) {
            return AutomationHealth::Blocked;
        }

        if ($watcher->health === WatcherHealth::Failed) {
            return AutomationHealth::Degraded;
        }

        return AutomationHealth::Healthy;
    }

    public function forReport(ScheduledReport $report): AutomationHealth
    {
        if ($report->status === ScheduledReportStatus::Paused || $report->status === ScheduledReportStatus::Cancelled) {
            return AutomationHealth::Disabled;
        }

        $code = (string) $report->last_error_code;
        if (in_array($code, ['blocked_auth', 'google_not_connected', 'gmail_scope_required'], true)) {
            return AutomationHealth::Blocked;
        }

        if ($code !== '') {
            return AutomationHealth::Degraded;
        }

        return AutomationHealth::Healthy;
    }

    public function forReminder(Reminder $reminder): AutomationHealth
    {
        if (in_array($reminder->status, [ReminderStatus::Cancelled, ReminderStatus::Completed, ReminderStatus::Failed], true)) {
            return $reminder->status === ReminderStatus::Failed ? AutomationHealth::Degraded : AutomationHealth::Disabled;
        }

        $metadata = is_array($reminder->metadata) ? $reminder->metadata : [];
        if (($metadata['delivery_state'] ?? '') === 'error') {
            return AutomationHealth::Degraded;
        }

        return AutomationHealth::Healthy;
    }
}
