<?php

namespace App\Services\OperationalControl\Rules;

use App\Enums\IntegrationAccountStatus;
use App\Enums\IntegrationHealth;
use App\Enums\OperationalActionability;
use App\Enums\OperationalEventType;
use App\Enums\OperationalRuleKey;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalType;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\OperationalControl\OperationalFingerprint;
use App\Services\OperationalControl\OperationalRuleMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class BlockedIntegrationRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::BlockedIntegration->value;
    }

    public function evaluate(User $user): array
    {
        if (! Schema::hasTable('integration_accounts')) {
            return [];
        }

        $accounts = IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                $query->where('health', IntegrationHealth::Blocked)
                    ->orWhere('status', IntegrationAccountStatus::Error)
                    ->orWhere('status', IntegrationAccountStatus::Revoked)
                    ->orWhere('last_error_code', 'blocked_auth');
            })
            ->orderBy('id')
            ->get();

        $matches = [];
        foreach ($accounts as $account) {
            $label = trim((string) ($account->display_label ?: $account->external_account_email ?: $account->provider));
            $code = (string) ($account->last_error_code ?: 'blocked');
            $core = in_array($code, ['blocked_auth', 'google_not_connected', 'gmail_scope_required'], true);
            $severity = $core ? OperationalSeverity::Critical : OperationalSeverity::High;

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::IntegrationBlocked,
                severity: $severity,
                fingerprint: OperationalFingerprint::make('integration.blocked', (string) $account->id, $code),
                rationale: ($label !== '' ? $label : 'An integration').' is disconnected. Reconnect.',
                proposalType: ProactiveProposalType::ReconnectIntegration,
                title: 'Reconnect '.$label,
                recommendedAction: 'reconnect_integration',
                actionability: OperationalActionability::ExecutePossible,
                occurredAt: $account->last_error_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                evidence: [
                    'integration_account_id' => $account->id,
                    'provider' => $account->provider,
                    'error_code' => $code,
                ],
                payload: [
                    'provider' => $account->provider,
                    'label' => $label,
                    'error_code' => $code,
                ],
                sourceType: 'integration_account',
                sourceId: (int) $account->id,
                sourceExternalId: (string) $account->external_account_id,
                confidence: 'high',
                evidencePointer: 'integration_account:'.$account->id,
                href: '/lavr?settings=integrations',
            );
        }

        return $matches;
    }
}
