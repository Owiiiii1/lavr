<?php

namespace App\Services\Automation;

use App\Enums\AutomationIntentKind;
use App\Services\Reports\ScheduledReportIntent;
use App\Services\Watchers\ProactiveCheckIntent;

final class AutomationIntentRouter
{
    public function classify(string $text): AutomationIntentKind
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return AutomationIntentKind::Clarify;
        }

        if (ProactiveCheckIntent::userSelfReminder($normalized)) {
            return AutomationIntentKind::Reminder;
        }

        if (ProactiveCheckIntent::isGmailEventMonitoring($text)
            || preg_match('/(?:жди|следи|watch|notify).{0,48}(?:письм|mail|email)/u', $normalized) === 1) {
            return AutomationIntentKind::Watcher;
        }

        if ($this->isAmbiguousPeriodicMail($normalized)) {
            return AutomationIntentKind::Clarify;
        }

        if (ScheduledReportIntent::matches($text) || ProactiveCheckIntent::jarvisShouldMonitorMail($text)) {
            return AutomationIntentKind::ScheduledReport;
        }

        if ($this->isConditionWatcher($normalized)) {
            return AutomationIntentKind::Watcher;
        }

        return AutomationIntentKind::Clarify;
    }

    private function isConditionWatcher(string $normalized): bool
    {
        return preg_match('/приш[её]л\s+(ли\s+)?файл|придёт\s+файл|when.{0,24}file|если.{0,40}не готов|still open|останет.{0,24}открыт/u', $normalized) === 1;
    }

    private function isAmbiguousPeriodicMail(string $normalized): bool
    {
        $periodic = preg_match('/кажд|по утрам|every (?:morning|day)|daily/u', $normalized) === 1;
        $mail = preg_match('/почт|gmail|inbox|письм/u', $normalized) === 1;
        $digest = preg_match('/сводк|отч[её]т|что нового|расскаж/u', $normalized) === 1;
        $condition = $this->isConditionWatcher($normalized) || preg_match('/жди|когда прид|следи за письм/u', $normalized) === 1;

        return $periodic && $mail && $digest && $condition;
    }
}
