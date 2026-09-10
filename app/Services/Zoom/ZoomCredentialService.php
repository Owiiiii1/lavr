<?php

namespace App\Services\Zoom;

use App\Enums\IntegrationAccountStatus;
use App\Enums\UserRole;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Users\UserCapability;
use App\Services\Zoom\Exceptions\ZoomException;

final class ZoomCredentialService
{
    public function __construct(
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function accountForOwner(User $owner): ?IntegrationAccount
    {
        return IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'zoom')
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [IntegrationAccountStatus::Connected->value])
            ->orderByDesc('id')
            ->first();
    }

    public function connectedAccount(): ?IntegrationAccount
    {
        return IntegrationAccount::query()
            ->where('provider', 'zoom')
            ->where('status', IntegrationAccountStatus::Connected)
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->first();
    }

    public function configuredAccount(): ?IntegrationAccount
    {
        $connected = $this->connectedAccount();

        if ($connected !== null) {
            return $connected;
        }

        return IntegrationAccount::query()
            ->where('provider', 'zoom')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{account_id: string, client_id: string, client_secret: string, webhook_secret: string, enabled: bool}
     */
    public function credentials(IntegrationAccount $account): array
    {
        $envelope = $this->accounts->getCredentials($account);

        return [
            'account_id' => trim((string) ($envelope['account_id'] ?? '')),
            'client_id' => trim((string) ($envelope['client_id'] ?? '')),
            'client_secret' => trim((string) ($envelope['client_secret'] ?? '')),
            'webhook_secret' => trim((string) ($envelope['webhook_secret'] ?? '')),
            'enabled' => (bool) ($envelope['enabled'] ?? true),
        ];
    }

    public function webhookSecret(?IntegrationAccount $account = null): string
    {
        $account ??= $this->configuredAccount();

        if ($account === null) {
            return '';
        }

        return $this->credentials($account)['webhook_secret'];
    }

    public function isEnabled(IntegrationAccount $account): bool
    {
        return $this->credentials($account)['enabled'] === true;
    }

    public function hasOAuthCredentials(IntegrationAccount $account): bool
    {
        $credentials = $this->credentials($account);

        return $credentials['account_id'] !== ''
            && $credentials['client_id'] !== ''
            && $credentials['client_secret'] !== '';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(User $owner, array $input): IntegrationAccount
    {
        if (! $owner->isActive()
            || $owner->role !== UserRole::Owner
            || ! $owner->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            throw new ZoomException('forbidden', 'Zoom settings are owner-only.');
        }

        $existing = $this->accountForOwner($owner);
        $current = $existing !== null ? $this->credentials($existing) : [
            'account_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'webhook_secret' => '',
            'enabled' => true,
        ];

        $accountId = $this->optionalString($input['account_id'] ?? null) ?? $current['account_id'];
        $clientId = $this->optionalString($input['client_id'] ?? null) ?? $current['client_id'];
        $clientSecret = $this->optionalString($input['client_secret'] ?? null) ?? $current['client_secret'];
        $webhookSecret = $this->optionalString($input['webhook_secret'] ?? null) ?? $current['webhook_secret'];
        $enabled = array_key_exists('enabled', $input)
            ? (bool) $input['enabled']
            : $current['enabled'];

        $account = $this->accounts->upsertAccount(
            $owner,
            'zoom',
            $accountId,
            null,
            $this->hasCompleteOAuth($accountId, $clientId, $clientSecret)
                ? ($existing?->status ?? IntegrationAccountStatus::Disconnected)
                : IntegrationAccountStatus::Disconnected,
            ZoomConfig::scopes(),
            is_array($existing?->metadata) ? $existing->metadata : [],
        );

        $this->accounts->setCredentials($account, [
            'account_id' => $accountId,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'webhook_secret' => $webhookSecret,
            'enabled' => $enabled,
        ]);

        $account->refresh();

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPayload(User $owner): array
    {
        $account = $this->accountForOwner($owner);
        $credentials = $account !== null ? $this->credentials($account) : [
            'account_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'webhook_secret' => '',
            'enabled' => false,
        ];
        $metadata = is_array($account?->metadata) ? $account->metadata : [];

        return [
            'configured' => $account !== null && $this->hasOAuthCredentials($account),
            'enabled' => $account !== null && $this->isEnabled($account),
            'status' => $account?->status instanceof IntegrationAccountStatus
                ? $account->status->value
                : ($account?->status ?? IntegrationAccountStatus::Disconnected->value),
            'account_id' => $credentials['account_id'] !== '' ? $credentials['account_id'] : null,
            'client_id' => $credentials['client_id'] !== '' ? $credentials['client_id'] : null,
            'has_client_secret' => $credentials['client_secret'] !== '',
            'has_webhook_secret' => $credentials['webhook_secret'] !== '',
            'webhook_url' => ZoomConfig::webhookUrl(),
            'webhook_configured' => $credentials['webhook_secret'] !== '',
            'last_success_at' => optional($account?->last_success_at)?->toIso8601String(),
            'last_error_at' => optional($account?->last_error_at)?->toIso8601String(),
            'last_error_code' => $account?->last_error_code,
            'last_event_at' => isset($metadata['last_event_at']) ? (string) $metadata['last_event_at'] : null,
            'last_event_name' => isset($metadata['last_event_name']) ? (string) $metadata['last_event_name'] : null,
            'last_import_at' => isset($metadata['last_import_at']) ? (string) $metadata['last_import_at'] : null,
            'last_checked_at' => optional($account?->last_used_at)?->toIso8601String(),
            'scopes' => ZoomConfig::scopes(),
        ];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function mergeMetadata(IntegrationAccount $account, array $patch): void
    {
        $metadata = is_array($account->metadata) ? $account->metadata : [];
        $account->forceFill([
            'metadata' => array_merge($metadata, $patch),
        ])->save();
    }

    private function hasCompleteOAuth(string $accountId, string $clientId, string $clientSecret): bool
    {
        return $accountId !== '' && $clientId !== '' && $clientSecret !== '';
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
