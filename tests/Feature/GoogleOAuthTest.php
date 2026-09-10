<?php

namespace Tests\Feature;

use App\Enums\IntegrationAccountStatus;
use App\Enums\UserRole;
use App\Models\GoogleOAuthSetting;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Conversations\ConversationService;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleCredentialService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\SearchGroupKnowledgeTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\RestoresGoogleOAuthSettings;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresGoogleOAuthSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotGoogleOAuthSettings();
        GoogleOAuthSetting::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->restoreGoogleOAuthSettings();

        parent::tearDown();
    }

    public function test_owner_connect_redirects_to_google_and_user_is_denied(): void
    {
        $owner = null;
        $user = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();

            $response = $this->actingAs($owner)->get(route('integrations.google.connect'));
            $response->assertRedirect();
            $location = $response->headers->get('Location');
            $this->assertIsString($location);
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            $this->assertSame('test-google-client-id', $query['client_id'] ?? null);
            $this->assertSame('https://jarvis.example.test/integrations/google/callback', $query['redirect_uri'] ?? null);
            $this->assertSame('code', $query['response_type'] ?? null);
            $this->assertSame('offline', $query['access_type'] ?? null);
            $this->assertSame('consent', $query['prompt'] ?? null);
            $this->assertSame('S256', $query['code_challenge_method'] ?? null);
            $this->assertNotEmpty($query['state'] ?? null);
            $this->assertStringContainsString('openid', (string) ($query['scope'] ?? ''));
            $this->assertStringNotContainsString('calendar', (string) ($query['scope'] ?? ''));
            $this->assertStringNotContainsString('test-google-client-secret', $location);
            $this->assertNotEmpty(session('google_oauth_state')['state'] ?? null);

            $this->actingAs($user)->get(route('integrations.google.connect'))->assertForbidden();
            auth()->logout();
            $this->get(route('integrations.google.connect'))->assertRedirect(route('login'));
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_callback_creates_encrypted_account(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $this->fakeGoogleHttp();
            $state = $this->startConnect($owner);

            $response = $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => $state,
                'code' => 'synthetic-auth-code',
            ]));

            $response->assertRedirect(route('settings.index', ['tab' => 'integrations']));
            $response->assertSessionHas('success');

            $account = IntegrationAccount::query()->where('user_id', $owner->id)->where('provider', 'google')->first();
            $this->assertNotNull($account);
            $this->assertSame(IntegrationAccountStatus::Connected, $account->status);
            $this->assertSame('google-sub-1', $account->external_account_id);
            $this->assertSame('owner@example.test', $account->external_account_email);
            $this->assertContains('openid', $account->scopes ?? []);

            $raw = DB::table('integration_accounts')->where('id', $account->id)->value('credentials_encrypted');
            $this->assertIsString($raw);
            $this->assertStringNotContainsString('synthetic-access-token', (string) $raw);
            $this->assertStringNotContainsString('synthetic-refresh-token', (string) $raw);

            $envelope = app(IntegrationAccountService::class)->getCredentials($account);
            $this->assertSame('synthetic-access-token', $envelope['access_token']);
            $this->assertSame('synthetic-refresh-token', $envelope['refresh_token']);
            $this->assertArrayNotHasKey('credentials_encrypted', $account->toArray());
            $this->assertStringNotContainsString('synthetic-access-token', (string) json_encode($account));

            $page = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $page->assertOk();
            $html = $page->getContent();
            $this->assertStringContainsString('Connected', $html);
            $this->assertStringContainsString('owner@example.test', $html);
            $this->assertStringNotContainsString('synthetic-access-token', $html);
            $this->assertStringNotContainsString('synthetic-refresh-token', $html);
            $this->assertStringNotContainsString((string) $raw, $html);

            $this->assertNull(session('google_oauth_state'));
            Http::assertSentCount(2);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_invalid_state_and_access_denied_do_not_create_accounts(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $this->fakeGoogleHttp();
            $this->startConnect($owner);

            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => 'wrong-state',
                'code' => 'synthetic-auth-code',
            ]))->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $this->assertSame(0, IntegrationAccount::query()->where('user_id', $owner->id)->count());
            Http::assertNothingSent();

            $this->startConnect($owner);
            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'error' => 'access_denied',
            ]))->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $this->assertSame(0, IntegrationAccount::query()->where('user_id', $owner->id)->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_used_state_is_rejected(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $this->fakeGoogleHttp();
            $state = $this->startConnect($owner);

            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => $state,
                'code' => 'synthetic-auth-code',
            ]))->assertRedirect();

            Http::fake();
            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => $state,
                'code' => 'second-code',
            ]))->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $this->assertSame(1, IntegrationAccount::query()->where('user_id', $owner->id)->count());
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_refresh_preserves_refresh_token_and_handles_invalid_grant(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $accounts = app(IntegrationAccountService::class);
            $credentials = app(GoogleCredentialService::class);

            $account = $accounts->upsertAccount($owner, 'google', 'google-sub-1', 'owner@example.test', IntegrationAccountStatus::Connected);
            $accounts->setCredentials($account, [
                'access_token' => 'old-access',
                'refresh_token' => 'keep-this-refresh',
                'expires_at' => now()->subMinute()->toIso8601String(),
            ]);
            $accounts->markConnected($account);

            $merged = $credentials->mergeTokenResponse(
                $accounts->getCredentials($account),
                ['access_token' => 'new-access', 'expires_in' => 3600],
            );
            $this->assertSame('keep-this-refresh', $merged['refresh_token']);
            $this->assertSame('new-access', $merged['access_token']);

            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response([
                    'access_token' => 'rotated-access',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ], 200),
            ]);

            $token = $credentials->getValidAccessToken($account->fresh());
            $this->assertSame('rotated-access', $token);
            $this->assertSame('keep-this-refresh', $accounts->getCredentials($account->fresh())['refresh_token']);

            $again = $credentials->getValidAccessToken($account->fresh());
            $this->assertSame('rotated-access', $again);
            $this->assertSame(1, collect(Http::recorded())->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_invalid_grant_marks_account_revoked(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $accounts = app(IntegrationAccountService::class);
            $account = $accounts->upsertAccount($owner, 'google', 'google-sub-1', 'owner@example.test', IntegrationAccountStatus::Connected);
            $accounts->setCredentials($account, [
                'access_token' => 'expired-access',
                'refresh_token' => 'dead-refresh',
                'expires_at' => now()->subMinutes(10)->toIso8601String(),
            ]);
            $accounts->markConnected($account);

            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            ]);

            try {
                app(GoogleCredentialService::class)->refresh($account->fresh());
                $this->fail('Expected refresh_revoked');
            } catch (IntegrationException $exception) {
                $this->assertSame('refresh_revoked', $exception->error);
            }

            $account->refresh();
            $this->assertSame(IntegrationAccountStatus::Revoked, $account->status);
            $this->assertSame([], $accounts->getCredentials($account));
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_disconnect_wipes_credentials_even_if_revoke_fails(): void
    {
        $owner = null;
        $user = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();
            $accounts = app(IntegrationAccountService::class);
            $account = $accounts->upsertAccount($owner, 'google', 'google-sub-1', 'owner@example.test', IntegrationAccountStatus::Connected);
            $accounts->setCredentials($account, [
                'access_token' => 'synthetic-access-token',
                'refresh_token' => 'synthetic-refresh-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]);
            $accounts->markConnected($account);

            Http::fake([
                'https://oauth2.googleapis.com/revoke' => Http::response('', 503),
            ]);

            $this->actingAs($owner)->post(route('integrations.google.disconnect'))
                ->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $account->refresh();
            $this->assertSame(IntegrationAccountStatus::Disconnected, $account->status);
            $this->assertSame([], $accounts->getCredentials($account));

            $this->actingAs($user)->post(route('integrations.google.disconnect'))->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_missing_config_is_safe_and_tools_unchanged(): void
    {
        $owner = null;
        $user = null;

        try {
            config([
                'integrations.google.client_id' => '',
                'integrations.google.client_secret' => '',
            ]);
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();

            $page = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $page->assertOk();
            $this->assertStringContainsString('Not configured', $page->getContent());

            $this->actingAs($owner)->get(route('integrations.google.connect'))
                ->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $this->actingAs($user)->get(route('integrations.google.callback', [
                'state' => 'x',
                'code' => 'y',
            ]))->assertForbidden();

            $this->assertSame(0, IntegrationAccount::query()->where('user_id', $owner->id)->count());

            $ownerChat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $names = array_map(
                static fn ($tool) => $tool->name,
                app(ToolRegistry::class)->definitionsFor(new ToolExecutionContext($owner, $ownerChat)),
            );
            foreach ([
                CreateReminderTool::NAME,
                SearchConversationHistoryTool::NAME,
                GetProjectContextTool::NAME,
                SearchGroupKnowledgeTool::NAME,
                'list_google_calendars',
                'search_gmail',
                'list_meetings',
            ] as $name) {
                $this->assertContains($name, $names);
            }
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_reconnect_same_subject_does_not_duplicate(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::sequence()
                    ->push([
                        'access_token' => 'synthetic-access-token',
                        'refresh_token' => 'synthetic-refresh-token',
                        'expires_in' => 3600,
                        'token_type' => 'Bearer',
                        'scope' => 'openid email profile',
                    ])
                    ->push([
                        'access_token' => 'synthetic-access-token-2',
                        'refresh_token' => 'synthetic-refresh-token-2',
                        'expires_in' => 3600,
                        'token_type' => 'Bearer',
                        'scope' => 'openid email profile',
                    ]),
                'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                    'sub' => 'google-sub-1',
                    'email' => 'owner@example.test',
                    'email_verified' => true,
                ], 200),
            ]);
            $state = $this->startConnect($owner);
            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => $state,
                'code' => 'first',
            ]))->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $state = $this->startConnect($owner);
            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => $state,
                'code' => 'second',
            ]))->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $this->assertSame(1, IntegrationAccount::query()->where('user_id', $owner->id)->where('provider', 'google')->count());
            $account = IntegrationAccount::query()->where('user_id', $owner->id)->first();
            $this->assertSame('google-sub-1', $account?->external_account_id);
            $this->assertSame('synthetic-access-token-2', app(IntegrationAccountService::class)->getCredentials($account)['access_token']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    private function configureGoogle(): void
    {
        config([
            'app.url' => 'https://jarvis.example.test',
            'integrations.google.client_id' => 'test-google-client-id',
            'integrations.google.client_secret' => 'test-google-client-secret',
            'integrations.google.redirect_uri' => 'https://jarvis.example.test/integrations/google/callback',
        ]);
    }

    private function fakeGoogleHttp(string $access = 'synthetic-access-token', string $refresh = 'synthetic-refresh-token'): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => $access,
                'refresh_token' => $refresh,
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'openid email profile',
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-sub-1',
                'email' => 'owner@example.test',
                'email_verified' => true,
            ], 200),
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);
    }

    private function startConnect(User $owner): string
    {
        $this->actingAs($owner)->get(route('integrations.google.connect'))->assertRedirect();

        return (string) session('google_oauth_state')['state'];
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
