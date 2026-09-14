<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationAccountStatus;
use App\Enums\IntegrationHealth;
use App\Models\IntegrationAccount;

final class IntegrationHealthService
{
    public function refresh(IntegrationAccount $account): IntegrationHealth
    {
        $health = $this->derive($account);
        if ($account->health !== $health) {
            $account->forceFill(['health' => $health])->save();
        }

        return $health;
    }

    public function derive(IntegrationAccount $account): IntegrationHealth
    {
        if ($account->enabled !== true || $account->status === IntegrationAccountStatus::Disconnected) {
            return IntegrationHealth::Disabled;
        }

        if (in_array($account->status, [
            IntegrationAccountStatus::Error,
            IntegrationAccountStatus::Revoked,
        ], true)) {
            return IntegrationHealth::Blocked;
        }

        if ($account->last_error_code !== null && $account->last_error_at !== null) {
            $recent = $account->last_error_at->greaterThan(now()->subHours(6));
            $successAfter = $account->last_success_at !== null
                && $account->last_success_at->greaterThan($account->last_error_at);

            if ($recent && ! $successAfter) {
                return IntegrationHealth::Degraded;
            }
        }

        return IntegrationHealth::Healthy;
    }

    /**
     * @return array{health: string, last_success_at: ?string, last_event_at: ?string, last_processed_at: ?string}
     */
    public function freshness(IntegrationAccount $account): array
    {
        return [
            'health' => $this->derive($account)->value,
            'last_success_at' => optional($account->last_success_at)?->toIso8601String(),
            'last_event_at' => optional($account->last_event_at)?->toIso8601String(),
            'last_processed_at' => optional($account->last_processed_at)?->toIso8601String(),
        ];
    }
}
