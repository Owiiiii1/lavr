<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationAccountStatus;
use App\Enums\IntegrationHealth;
use App\Enums\UserRole;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Users\UserCapability;
use Illuminate\Support\Collection;

final class IntegrationAccountService
{
    public function getActiveAccount(User $user, string $provider): ?IntegrationAccount
    {
        $this->assertOwner($user);

        return $this->listEnabled($user, $provider)->first();
    }

    public function getAccount(User $user, int $accountId, ?string $provider = null): IntegrationAccount
    {
        $this->assertOwner($user);

        $query = IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->whereKey($accountId);

        if ($provider !== null) {
            $query->where('provider', $provider);
        }

        $account = $query->first();
        if ($account === null) {
            throw new IntegrationException('google_not_connected', 'Integration account was not found.');
        }

        return $account;
    }

    /**
     * @return Collection<int, IntegrationAccount>
     */
    public function listAccounts(User $user, ?string $provider = null): Collection
    {
        $this->assertOwner($user);

        return IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->when($provider !== null, fn ($query) => $query->where('provider', $provider))
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, IntegrationAccount>
     */
    public function listEnabled(User $user, string $provider): Collection
    {
        $this->assertOwner($user);

        return IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('enabled', true)
            ->where('status', IntegrationAccountStatus::Connected)
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  list<string>|null  $scopes
     * @param  array<string, mixed>|null  $metadata
     */
    public function upsertAccount(
        User $user,
        string $provider,
        ?string $externalAccountId = null,
        ?string $externalAccountEmail = null,
        IntegrationAccountStatus $status = IntegrationAccountStatus::Disconnected,
        ?array $scopes = null,
        ?array $metadata = null,
        ?string $displayLabel = null,
    ): IntegrationAccount {
        $this->assertOwner($user);

        $externalId = $externalAccountId ?? '';

        $account = IntegrationAccount::query()->firstOrNew([
            'user_id' => $user->id,
            'provider' => $provider,
            'external_account_id' => $externalId,
        ]);

        $payload = [
            'external_account_email' => $externalAccountEmail,
            'status' => $status,
            'scopes' => $scopes,
            'metadata' => $metadata ?? $account->metadata,
        ];

        if ($displayLabel !== null && trim($displayLabel) !== '') {
            $payload['display_label'] = trim($displayLabel);
        } elseif (! filled($account->display_label) && filled($externalAccountEmail)) {
            $payload['display_label'] = $externalAccountEmail;
        }

        $account->fill($payload);
        $account->save();

        return $account;
    }

    public function setLabel(IntegrationAccount $account, string $label): IntegrationAccount
    {
        $trimmed = mb_substr(trim($label), 0, 80);
        $account->forceFill([
            'display_label' => $trimmed !== '' ? $trimmed : $account->external_account_email,
        ])->save();

        return $account->fresh() ?? $account;
    }

    public function setEnabled(IntegrationAccount $account, bool $enabled): IntegrationAccount
    {
        $account->forceFill(['enabled' => $enabled])->save();
        app(IntegrationHealthService::class)->refresh($account->fresh() ?? $account);

        return $account->fresh() ?? $account;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function setCredentials(IntegrationAccount $account, array $credentials): void
    {
        $account->credentials_encrypted = $credentials;
        $account->save();
    }

    /**
     * Adapter-only. Never pass the result to UI, logs, or Inertia.
     *
     * @return array<string, mixed>
     */
    public function getCredentials(IntegrationAccount $account): array
    {
        return is_array($account->credentials_encrypted) ? $account->credentials_encrypted : [];
    }

    public function markConnected(IntegrationAccount $account): void
    {
        $account->forceFill([
            'status' => IntegrationAccountStatus::Connected,
            'enabled' => true,
            'health' => IntegrationHealth::Healthy,
            'connected_at' => now(),
            'disconnected_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
    }

    public function markError(IntegrationAccount $account, string $code, ?string $safeMessage = null): void
    {
        $account->forceFill([
            'status' => IntegrationAccountStatus::Error,
            'health' => IntegrationHealth::Blocked,
            'last_error_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => $this->safeError($safeMessage ?? $code),
        ])->save();
    }

    public function markRevoked(IntegrationAccount $account): void
    {
        $account->forceFill([
            'status' => IntegrationAccountStatus::Revoked,
            'enabled' => false,
            'health' => IntegrationHealth::Blocked,
            'disconnected_at' => now(),
            'credentials_encrypted' => null,
        ])->save();
    }

    public function recordAuthSuccess(IntegrationAccount $account): void
    {
        $account->forceFill([
            'last_success_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
        app(IntegrationHealthService::class)->refresh($account->fresh() ?? $account);
    }

    public function recordSuccess(IntegrationAccount $account): void
    {
        $account->forceFill([
            'last_used_at' => now(),
            'last_success_at' => now(),
            'last_processed_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
        app(IntegrationHealthService::class)->refresh($account->fresh() ?? $account);
    }

    public function recordError(IntegrationAccount $account, string $code, ?string $safeMessage = null): void
    {
        $account->forceFill([
            'last_used_at' => now(),
            'last_error_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => $this->safeError($safeMessage ?? $code),
        ])->save();
        app(IntegrationHealthService::class)->refresh($account->fresh() ?? $account);
    }

    public function recordEvent(IntegrationAccount $account): void
    {
        $account->forceFill([
            'last_event_at' => now(),
            'last_processed_at' => now(),
        ])->save();
    }

    public function disconnect(IntegrationAccount $account, bool $notifyProvider = true): void
    {
        if ($notifyProvider) {
            $provider = app(IntegrationRegistry::class)->get($account->provider);
            if ($provider instanceof IntegrationProvider) {
                $provider->disconnect($account);
            }
        }

        $account->forceFill([
            'status' => IntegrationAccountStatus::Disconnected,
            'enabled' => false,
            'health' => IntegrationHealth::Disabled,
            'disconnected_at' => now(),
            'credentials_encrypted' => null,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function safeSummary(IntegrationAccount $account): array
    {
        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'status' => $account->status instanceof IntegrationAccountStatus
                ? $account->status->value
                : (string) $account->status,
            'health' => $account->health instanceof IntegrationHealth
                ? $account->health->value
                : (string) $account->health,
            'enabled' => $account->enabled === true,
            'external_account_email' => $account->external_account_email,
            'display_label' => $account->label(),
            'scopes' => $account->scopes ?? [],
            'connected_at' => optional($account->connected_at)?->toIso8601String(),
            'last_used_at' => optional($account->last_used_at)?->toIso8601String(),
            'last_success_at' => optional($account->last_success_at)?->toIso8601String(),
            'last_event_at' => optional($account->last_event_at)?->toIso8601String(),
            'last_processed_at' => optional($account->last_processed_at)?->toIso8601String(),
            'last_error_at' => optional($account->last_error_at)?->toIso8601String(),
            'last_error_code' => $account->last_error_code,
            'last_error_message' => $account->last_error_message,
            'has_encrypted_credentials' => is_array($account->credentials_encrypted) && $account->credentials_encrypted !== [],
        ];
    }

    private function safeError(string $code): string
    {
        $trimmed = mb_substr(trim($code), 0, 120);

        return $trimmed === '' ? 'source_error' : $trimmed;
    }

    private function assertOwner(User $user): void
    {
        if (! $user->isActive()
            || $user->role !== UserRole::Owner
            || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            throw new IntegrationException('forbidden', 'Integrations are owner-only.');
        }
    }
}
