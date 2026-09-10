<?php

namespace App\Services\Workspace\Presentation;

use App\Enums\AutomationHealth;
use App\Enums\AutomationRunOutcome;
use App\Models\AutomationRun;

final class HumanAutomationResult
{
    public static function label(?AutomationRun $run): ?string
    {
        if ($run === null) {
            return null;
        }

        $status = $run->status instanceof AutomationRunOutcome
            ? $run->status
            : AutomationRunOutcome::tryFrom((string) $run->status);

        return match ($status) {
            AutomationRunOutcome::Success => 'Доставлено',
            AutomationRunOutcome::Partial => 'Доставлено частково',
            AutomationRunOutcome::NoChange => 'Без змін',
            AutomationRunOutcome::Skipped => 'Пропущено',
            AutomationRunOutcome::Failed => 'Не виконано',
            AutomationRunOutcome::Retryable => 'Буде повтор',
            AutomationRunOutcome::Processing => 'Виконується',
            default => null,
        };
    }

    public static function healthBadge(AutomationHealth $health): ?string
    {
        return match ($health) {
            AutomationHealth::Blocked => 'blocked',
            AutomationHealth::Degraded => 'degraded',
            AutomationHealth::Disabled => 'paused',
            AutomationHealth::Healthy => null,
        };
    }
}
