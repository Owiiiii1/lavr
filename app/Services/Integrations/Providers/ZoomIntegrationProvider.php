<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\DTO\IntegrationStatus;
use App\Services\Users\UserCapability;
use App\Services\Zoom\ZoomCredentialService;

final class ZoomIntegrationProvider implements IntegrationProvider
{
    public function __construct(
        private readonly ZoomCredentialService $credentials,
    ) {}

    public function key(): string
    {
        return 'zoom';
    }

    public function displayName(): string
    {
        return 'Zoom';
    }

    public function capabilities(): array
    {
        return [
            UserCapability::MEETINGS,
        ];
    }

    public function requiresAccount(): bool
    {
        return true;
    }

    public function supportsConnect(): bool
    {
        return false;
    }

    public function status(User $owner): IntegrationStatus
    {
        $account = $this->credentials->accountForOwner($owner);
        $payload = $this->credentials->adminPayload($owner);
        $configured = (bool) $payload['configured'];
        $enabled = (bool) $payload['enabled'];
        $state = $account?->status ?? IntegrationAccountStatus::Disconnected;

        if ($account !== null && $account->last_error_code === 'blocked_auth') {
            $state = IntegrationAccountStatus::Error;
        }

        $label = match (true) {
            $state === IntegrationAccountStatus::Connected && $enabled => 'Connected',
            $state === IntegrationAccountStatus::Connected && ! $enabled => 'Disabled',
            $configured && $state === IntegrationAccountStatus::Error => 'Auth blocked',
            $configured => 'Configured',
            default => 'Not connected',
        };

        $diagnostics = [];
        if (is_string($payload['account_id']) && $payload['account_id'] !== '') {
            $diagnostics[] = 'Account '.$payload['account_id'];
        }
        $diagnostics[] = $payload['webhook_configured'] ? 'Webhook secret saved' : 'Webhook secret missing';
        $diagnostics[] = $enabled ? 'Enabled' : 'Disabled';
        if (is_string($payload['last_event_name']) && $payload['last_event_name'] !== '') {
            $diagnostics[] = 'Last event '.$payload['last_event_name'];
        }
        if (is_string($payload['last_import_at']) && $payload['last_import_at'] !== '') {
            $diagnostics[] = 'Last import '.$payload['last_import_at'];
        }
        if (is_string($payload['last_error_code']) && $payload['last_error_code'] !== '') {
            $diagnostics[] = 'Error '.$payload['last_error_code'];
        }

        return new IntegrationStatus(
            provider: $this->key(),
            displayName: $this->displayName(),
            state: $state,
            label: $label,
            accountLabel: $payload['account_id'],
            scopes: $payload['scopes'] ?? [],
            lastSuccessAt: $payload['last_success_at'],
            lastErrorAt: $payload['last_error_at'],
            diagnosticMessage: implode(' · ', $diagnostics),
            actions: [
                ['key' => 'test', 'available' => $configured, 'label' => 'Test Zoom Connection'],
                ['key' => 'disconnect', 'available' => $account !== null, 'label' => 'Disconnect'],
            ],
            configured: $configured,
            connectedAt: optional($account?->connected_at)?->toIso8601String(),
            lastErrorCode: $account?->last_error_code,
            accountStatusLabel: $label,
        );
    }

    public function disconnect(IntegrationAccount $account): void
    {
        // Credentials are cleared by IntegrationAccountService.
    }
}
