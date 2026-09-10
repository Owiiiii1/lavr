<?php

namespace Tests\Feature;

use App\Enums\IntegrationAccountStatus;
use App\Enums\UserRole;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Zoom\Exceptions\ZoomException;
use App\Services\Zoom\ZoomCredentialService;
use App\Services\Zoom\ZoomOAuthClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ZoomOAuthTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_token_fetch_success_and_cache(): void
    {
        $owner = null;

        try {
            Http::preventStrayRequests();
            Http::fake([
                'https://zoom.us/oauth/token' => Http::response([
                    'access_token' => 'zoom-access-token',
                    'token_type' => 'bearer',
                    'expires_in' => 3600,
                ], 200),
            ]);
            $owner = $this->zoomOwner();
            $account = $this->saveZoom($owner);
            $client = app(ZoomOAuthClient::class);

            $first = $client->accessToken($account);
            $second = $client->accessToken($account->fresh());

            $this->assertSame('zoom-access-token', $first);
            $this->assertSame('zoom-access-token', $second);
            Http::assertSentCount(1);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_token_refresh_after_expiry(): void
    {
        $owner = null;

        try {
            Http::preventStrayRequests();
            Http::fake([
                'https://zoom.us/oauth/token' => Http::sequence()
                    ->push(['access_token' => 'token-one', 'expires_in' => 3600], 200)
                    ->push(['access_token' => 'token-two', 'expires_in' => 3600], 200),
            ]);
            $owner = $this->zoomOwner();
            $account = $this->saveZoom($owner);
            $client = app(ZoomOAuthClient::class);

            $this->assertSame('token-one', $client->accessToken($account));
            $client->forgetToken($account);
            $this->assertSame('token-two', $client->accessToken($account));
            Http::assertSentCount(2);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_invalid_credentials_mark_blocked_auth(): void
    {
        $owner = null;

        try {
            Http::preventStrayRequests();
            Http::fake([
                'https://zoom.us/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
            ]);
            $owner = $this->zoomOwner();
            $account = $this->saveZoom($owner);

            try {
                app(ZoomOAuthClient::class)->accessToken($account, true);
                $this->fail('Expected ZoomException');
            } catch (ZoomException $exception) {
                $this->assertSame('blocked_auth', $exception->error);
                $this->assertFalse($exception->retryable);
            }

            $account->refresh();
            $this->assertSame(IntegrationAccountStatus::Error, $account->status);
            $this->assertSame('blocked_auth', $account->last_error_code);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_token_is_never_logged(): void
    {
        $owner = null;

        try {
            Http::preventStrayRequests();
            Http::fake([
                'https://zoom.us/oauth/token' => Http::response([
                    'access_token' => 'super-secret-zoom-token',
                    'expires_in' => 3600,
                ], 200),
            ]);
            $logged = [];
            Log::listen(function ($event) use (&$logged): void {
                $logged[] = $event->message.' '.json_encode($event->context);
            });
            $owner = $this->zoomOwner();
            $account = $this->saveZoom($owner);
            app(ZoomOAuthClient::class)->accessToken($account, true);

            $blob = implode("\n", $logged);
            $this->assertStringNotContainsString('super-secret-zoom-token', $blob);
            $this->assertStringNotContainsString('zoom-client-secret', $blob);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    private function zoomOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }

    private function saveZoom(User $owner): IntegrationAccount
    {
        Cache::flush();

        return app(ZoomCredentialService::class)->save($owner, [
            'account_id' => 'configured-account',
            'client_id' => 'zoom-client-id',
            'client_secret' => 'zoom-client-secret',
            'webhook_secret' => 'zoom-webhook-secret',
            'enabled' => true,
        ]);
    }
}
