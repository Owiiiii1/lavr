<?php

namespace App\Services\Automation;

use Carbon\CarbonImmutable;

final class AutomationRunKey
{
    public static function reminder(int $id, CarbonImmutable $runAt): string
    {
        return 'reminder:'.$id.':'.$runAt->utc()->format('Y-m-d\TH:i');
    }

    public static function watcherPoll(int $id, CarbonImmutable $slot): string
    {
        return 'watcher:'.$id.':poll:'.$slot->utc()->format('Y-m-d\TH:i');
    }

    public static function scheduledReport(int $id, string $slotKey): string
    {
        return 'scheduled_report:'.$id.':'.$slotKey;
    }

    public static function commitmentTransition(int $id, string $status): string
    {
        return 'commitment:'.$id.':'.$status.':v1';
    }

    public static function executiveBrief(int $userId, string $type, string $localDate): string
    {
        return 'executive_brief:'.$userId.':'.$type.':'.$localDate;
    }

    public static function executiveBriefManual(int $userId, string $type, string $localDate, string $suffix): string
    {
        return 'executive_brief:'.$userId.':'.$type.':'.$localDate.':manual:'.$suffix;
    }

    public static function leadershipReviewWeekly(int $userId, string $periodStart): string
    {
        return 'leadership_review:'.$userId.':weekly:'.$periodStart;
    }

    public static function leadershipReviewManual(int $userId, string $type, string $periodStart, string $suffix): string
    {
        return 'leadership_review:'.$userId.':'.$type.':'.$periodStart.':manual:'.$suffix;
    }

    public static function brief(int $userId, string $mode, string $localDate): string
    {
        return 'brief:'.$userId.':'.$mode.':'.$localDate;
    }

    public static function proactive(int $userId, string $dedupe): string
    {
        return 'proactive:'.$userId.':'.$dedupe;
    }
}
