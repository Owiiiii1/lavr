<?php

namespace App\Services\Zoom;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Zoom\Exceptions\ZoomException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ZoomOAuthClient
{
    public function __construct(
        private readonly ZoomCredentialService $credentials,
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function accessToken(IntegrationAccount $account, bool $forceRefresh = false): string
    {
        if (! $this->credentials->hasOAuthCredentials($account)) {
            $this->accounts->markError($account, 'blocked_auth');

            throw new ZoomException('blocked_auth', 'Zoom OAuth credentials are incomplete.', false);
        }

        $cacheKey = $this->cacheKey($account);

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $token = $this->requestToken($account);
        $ttl = max(30, $token['expires_in'] - ZoomConfig::tokenSkewSeconds());
        Cache::put($cacheKey, $token['access_token'], $ttl);

        return $token['access_token'];
    }

    public function forgetToken(IntegrationAccount $account): void
    {
        Cache::forget($this->cacheKey($account));
    }

    /**
     * @return array{ok: bool, error: string|null, account_id: string|null}
     */
    public function testConnection(IntegrationAccount $account): array
    {
        $this->forgetToken($account);

        try {
            $token = $this->accessToken($account, true);
            $response = Http::connectTimeout(ZoomConfig::connectTimeout())
                ->timeout(ZoomConfig::timeout())
                ->withToken($token)
                ->acceptJson()
                ->get(ZoomConfig::apiBaseUrl().'/users/me');

            if ($response->status() === 401) {
                $this->markBlockedAuth($account, 'invalid_credentials');

                return ['ok' => false, 'error' => 'blocked_auth', 'account_id' => null];
            }

            if ($response->successful() || $response->status() === 403) {
                $this->accounts->markConnected($account);
                $this->accounts->recordAuthSuccess($account);

                return [
                    'ok' => true,
                    'error' => null,
                    'account_id' => $this->credentials->credentials($account)['account_id'] ?: null,
                ];
            }

            if ($response->status() === 429) {
                $this->accounts->recordError($account, 'rate_limited');

                return ['ok' => false, 'error' => 'rate_limited', 'account_id' => null];
            }

            $this->accounts->recordError($account, 'connection_failed');

            return ['ok' => false, 'error' => 'connection_failed', 'account_id' => null];
        } catch (ZoomException $exception) {
            return ['ok' => false, 'error' => $exception->error, 'account_id' => null];
        } catch (ConnectionException $exception) {
            $this->accounts->recordError($account, 'network');

            return ['ok' => false, 'error' => 'network', 'account_id' => null];
        }
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    private function requestToken(IntegrationAccount $account): array
    {
        $credentials = $this->credentials->credentials($account);

        try {
            $response = Http::connectTimeout(ZoomConfig::connectTimeout())
                ->timeout(ZoomConfig::timeout())
                ->asForm()
                ->withBasicAuth($credentials['client_id'], $credentials['client_secret'])
                ->post(ZoomConfig::oauthTokenUrl(), [
                    'grant_type' => 'account_credentials',
                    'account_id' => $credentials['account_id'],
                ]);
        } catch (ConnectionException $exception) {
            $this->accounts->recordError($account, 'network');

            throw new ZoomException('network', 'Zoom OAuth request failed.', true, previous: $exception);
        }

        if ($response->status() === 401 || $response->status() === 400) {
            $this->markBlockedAuth($account, 'invalid_credentials');

            throw new ZoomException('blocked_auth', 'Zoom rejected the Server-to-Server OAuth credentials.', false);
        }

        if ($response->status() === 429) {
            $retryAfter = $this->retryAfterSeconds($response);
            $this->accounts->recordError($account, 'rate_limited');

            throw new ZoomException('rate_limited', 'Zoom OAuth rate limited.', true, $retryAfter);
        }

        if (! $response->successful()) {
            $this->accounts->recordError($account, 'oauth_failed');

            throw new ZoomException('oauth_failed', 'Zoom OAuth token request failed.', true);
        }

        $accessToken = trim((string) $response->json('access_token'));
        $expiresIn = (int) $response->json('expires_in', 3600);

        if ($accessToken === '') {
            $this->accounts->recordError($account, 'oauth_failed');

            throw new ZoomException('oauth_failed', 'Zoom OAuth token was empty.', true);
        }

        Log::info('zoom oauth token fetched', [
            'integration_account_id' => $account->id,
            'expires_in' => $expiresIn,
        ]);

        $this->accounts->recordAuthSuccess($account);

        return [
            'access_token' => $accessToken,
            'expires_in' => max(60, $expiresIn),
        ];
    }

    private function markBlockedAuth(IntegrationAccount $account, string $code): void
    {
        $this->forgetToken($account);
        $this->accounts->markError($account, $code);
        $account->forceFill([
            'status' => IntegrationAccountStatus::Error,
            'last_error_code' => 'blocked_auth',
            'last_error_at' => now(),
        ])->save();

        Log::warning('zoom oauth blocked', [
            'integration_account_id' => $account->id,
            'error' => $code,
        ]);
    }

    private function retryAfterSeconds(Response $response): int
    {
        $header = $response->header('Retry-After');

        if ($header !== null && is_numeric($header)) {
            return max(1, (int) $header);
        }

        return 60;
    }

    private function cacheKey(IntegrationAccount $account): string
    {
        return 'zoom:s2s:token:'.$account->id;
    }
}
