<?php

namespace App\Services\Integrations\Google;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use Illuminate\Support\Facades\Log;

final class GoogleConnectionService
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleOAuthState $state,
        private readonly GoogleCredentialService $credentials,
        private readonly IntegrationAccountService $accounts,
    ) {}

    /**
     * @param  list<string>  $additionalScopes
     */
    public function authorizationUrl(User $owner, array $additionalScopes = []): string
    {
        $connected = $this->accounts->listAccounts($owner, 'google')
            ->where('status', IntegrationAccountStatus::Connected)
            ->count();
        $forceConsent = $connected > 0 || ! $this->hasRefreshToken($owner);
        $authorization = $this->oauth->buildAuthorizationUrl($forceConsent, $additionalScopes, $connected > 0);
        $this->state->start($owner, $authorization['state'], $authorization['verifier']);

        Log::info('google oauth', [
            'provider' => 'google',
            'action' => 'connect',
            'success' => true,
        ]);

        return $authorization['url'];
    }

    public function complete(User $owner, ?string $state, ?string $code, ?string $oauthError): IntegrationAccount
    {
        if (filled($oauthError)) {
            $this->state->clear();
            $code = $oauthError === 'access_denied' ? 'oauth_access_denied' : 'token_exchange_failed';
            throw new IntegrationException($code, 'Google authorization was not completed.');
        }

        $consumed = $this->state->consume($owner, $state);

        if (! filled($code)) {
            throw new IntegrationException('token_exchange_failed', 'Google authorization code is missing.');
        }

        $startedAt = microtime(true);
        $tokenResponse = $this->oauth->exchangeCode((string) $code, $consumed['verifier']);
        $identity = $this->oauth->fetchUserInfo((string) $tokenResponse['access_token']);
        $scopes = $this->mergeGrantedScopes($owner, $identity['sub'], $this->grantedScopes($tokenResponse));

        if (! $this->hasIdentityScopes($scopes)) {
            throw new IntegrationException('token_exchange_failed', 'Google did not grant required identity scopes.');
        }

        $account = $this->persistAccount($owner, $identity, $scopes, $tokenResponse);

        Log::info('google oauth', [
            'provider' => 'google',
            'action' => 'callback',
            'account_id' => $account->id,
            'success' => true,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $account;
    }

    public function disconnect(User $owner, IntegrationAccount $account): bool
    {
        if ((int) $account->user_id !== (int) $owner->id || $account->provider !== 'google') {
            throw new IntegrationException('forbidden', 'Google account does not belong to this owner.');
        }

        $revokedRemotely = $this->oauth->revokeSafely($this->revokeToken($account));
        $this->accounts->disconnect($account, notifyProvider: false);

        Log::info('google oauth', [
            'provider' => 'google',
            'action' => 'disconnect',
            'account_id' => $account->id,
            'success' => true,
            'error_code' => $revokedRemotely ? null : 'google_unavailable',
        ]);

        return $revokedRemotely;
    }

    /**
     * @param  array{sub: string, email: string}  $identity
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $tokenResponse
     */
    private function persistAccount(User $owner, array $identity, array $scopes, array $tokenResponse): IntegrationAccount
    {
        $existing = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'google')
            ->where('external_account_id', $identity['sub'])
            ->first();

        $merged = $this->credentials->mergeTokenResponse(
            $existing !== null ? $this->accounts->getCredentials($existing) : [],
            $tokenResponse,
        );

        $account = $this->accounts->upsertAccount(
            $owner,
            'google',
            $identity['sub'],
            $identity['email'],
            IntegrationAccountStatus::Connected,
            $scopes,
            $existing?->metadata,
            $existing?->display_label ?: $identity['email'],
        );

        $this->accounts->setCredentials($account, $merged);
        $this->accounts->markConnected($account);
        $this->accounts->recordAuthSuccess($account->fresh() ?? $account);

        return $account->fresh() ?? $account;
    }

    private function hasRefreshToken(User $owner): bool
    {
        $account = $this->accounts->listEnabled($owner, 'google')->first();
        if ($account === null) {
            return false;
        }

        $envelope = $this->accounts->getCredentials($account);

        return filled($envelope['refresh_token'] ?? null);
    }

    /**
     * @param  list<string>  $granted
     * @return list<string>
     */
    private function mergeGrantedScopes(User $owner, string $sub, array $granted): array
    {
        $existing = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'google')
            ->where('external_account_id', $sub)
            ->first();

        $existingScopes = is_array($existing?->scopes) ? $existing->scopes : [];

        return $this->oauth->normalizeScopes(array_merge($existingScopes, $granted));
    }

    /**
     * @param  array<string, mixed>  $tokenResponse
     * @return list<string>
     */
    private function grantedScopes(array $tokenResponse): array
    {
        $raw = $tokenResponse['scope'] ?? $this->oauth->requestedScopes();
        $scopes = is_string($raw) ? preg_split('/\s+/', $raw) ?: [] : (array) $raw;

        return $this->oauth->normalizeScopes($scopes);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function hasIdentityScopes(array $scopes): bool
    {
        return in_array('openid', $scopes, true) || in_array('email', $scopes, true);
    }

    private function revokeToken(IntegrationAccount $account): ?string
    {
        $envelope = $this->accounts->getCredentials($account);

        return filled($envelope['refresh_token'] ?? null)
            ? (string) $envelope['refresh_token']
            : (filled($envelope['access_token'] ?? null) ? (string) $envelope['access_token'] : null);
    }
}
