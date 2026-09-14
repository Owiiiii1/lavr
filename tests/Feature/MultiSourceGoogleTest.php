<?php

namespace Tests\Feature;

use App\Enums\IntegrationAccountStatus;
use App\Enums\ProjectSourceType;
use App\Enums\SourceBindingKind;
use App\Enums\UserRole;
use App\Models\GoogleOAuthSetting;
use App\Models\IntegrationAccount;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ConversationService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Projects\ProjectService;
use App\Services\Sources\ProjectSourceBindingService;
use App\Services\Sources\SourceDeletionService;
use App\Services\Tools\Google\ListCalendarEventsTool;
use App\Services\Tools\Sources\SearchEmailTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\InterpretsHttpClientRequests;
use Tests\Support\RestoresGoogleOAuthSettings;
use Tests\TestCase;

class MultiSourceGoogleTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use InterpretsHttpClientRequests;
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

    public function test_connecting_a_second_google_subject_keeps_the_first_account(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            Http::fake(function ($request) {
                $url = $request->url();
                if (str_contains($url, 'oauth2.googleapis.com/token')) {
                    $code = (string) ($this->httpForm($request)['code'] ?? '');
                    $second = $code === 'second';

                    return Http::response([
                        'access_token' => $second ? 'token-b' : 'token-a',
                        'refresh_token' => $second ? 'refresh-b' : 'refresh-a',
                        'expires_in' => 3600,
                        'token_type' => 'Bearer',
                        'scope' => 'openid email profile',
                    ], 200);
                }

                if (str_contains($url, 'openidconnect.googleapis.com/v1/userinfo')) {
                    $auth = $this->httpAuthorization($request);
                    $second = str_contains($auth, 'token-b');

                    return Http::response([
                        'sub' => $second ? 'google-sub-2' : 'google-sub-1',
                        'email' => $second ? 'chicago@example.test' : 'ceo@example.test',
                        'email_verified' => true,
                    ], 200);
                }

                if (str_contains($url, 'oauth2.googleapis.com/revoke')) {
                    return Http::response('', 200);
                }

                return Http::response(['error' => 'unexpected'], 404);
            });
            $state = $this->startConnect($owner);
            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => $state,
                'code' => 'first',
            ]))->assertRedirect();

            $state = $this->startConnect($owner);
            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => $state,
                'code' => 'second',
            ]))->assertRedirect();

            $accounts = IntegrationAccount::query()->where('user_id', $owner->id)->where('provider', 'google')->orderBy('id')->get();
            $this->assertCount(2, $accounts);
            $this->assertTrue($accounts->every(fn (IntegrationAccount $account): bool => $account->status === IntegrationAccountStatus::Connected));
            $credentials = app(IntegrationAccountService::class);
            $this->assertSame('token-a', $credentials->getCredentials($accounts[0])['access_token']);
            $this->assertSame('token-b', $credentials->getCredentials($accounts[1])['access_token']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_account_failure_does_not_break_sibling_search(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $a = $this->connectGoogle($owner, 'google-sub-a', 'chicago@example.test', 'token-a', 'Chicago');
            $b = $this->connectGoogle($owner, 'google-sub-b', 'milan@example.test', 'token-b', 'Milan');
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');

            Http::fake(function ($request) {
                $auth = $this->httpAuthorization($request);
                if (str_contains($auth, 'token-a')) {
                    return Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401);
                }

                if ($request->method() === 'GET' && str_contains($request->url(), '/gmail/v1/users/me/messages') && ! str_contains($request->url(), '/messages/m')) {
                    return Http::response(['messages' => [['id' => 'm-b']]], 200);
                }

                return Http::response([
                    'id' => 'm-b',
                    'threadId' => 'th-b',
                    'snippet' => 'Sony contract',
                    'labelIds' => ['INBOX'],
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'Sony <sony@example.test>'],
                            ['name' => 'Subject', 'value' => 'Contract'],
                            ['name' => 'Date', 'value' => 'Mon, 14 Sep 2026 10:00:00 +0000'],
                        ],
                    ],
                ], 200);
            });

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('s1', SearchEmailTool::NAME, ['query' => 'from:Sony']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('partial', $result->payload['semantics']);
            $this->assertCount(1, $result->payload['messages']);
            $this->assertSame($b->id, $result->payload['messages'][0]['account_id']);
            $this->assertNotEmpty($result->payload['unavailable']);
            $this->assertSame($a->id, $result->payload['unavailable'][0]['account_id']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_project_binding_scopes_search_and_explicit_binding_is_not_overwritten(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $chicago = $this->connectGoogle($owner, 'google-sub-chi', 'chicago@example.test', 'token-chi', 'Chicago');
            $this->connectGoogle($owner, 'google-sub-mil', 'milan@example.test', 'token-mil', 'Milan');
            $project = app(ProjectService::class)->create($owner, 'Chicago Show');
            app(ProjectSourceBindingService::class)->bind(
                $owner,
                $project,
                ProjectSourceType::GoogleMailbox,
                $chicago->id,
                SourceBindingKind::Explicit,
            );

            $suggested = app(ProjectSourceBindingService::class)->bind(
                $owner,
                $project,
                ProjectSourceType::GoogleMailbox,
                $chicago->id,
                SourceBindingKind::Suggested,
            );
            $this->assertSame(SourceBindingKind::Explicit, $suggested->binding_kind);

            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake(function ($request) {
                $auth = $this->httpAuthorization($request);
                $this->assertStringContainsString('token-chi', $auth);

                if ($request->method() === 'GET' && str_contains($request->url(), '/gmail/v1/users/me/messages') && ! preg_match('#/messages/[A-Za-z0-9_-]+$#', explode('?', $request->url())[0])) {
                    return Http::response(['messages' => []], 200);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('p1', SearchEmailTool::NAME, [
                    'query' => 'in:inbox',
                    'project_id' => $project->id,
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('empty', $result->payload['semantics']);
            $this->assertSame([], $result->payload['unavailable']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_disable_stops_processing_and_remove_keeps_canonical_project(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner, 'google-sub-fin', 'finance@example.test', 'token-fin', 'Finance');
            $project = app(ProjectService::class)->create($owner, 'Finance');
            app(ProjectSourceBindingService::class)->bind(
                $owner,
                $project,
                ProjectSourceType::GoogleMailbox,
                $account->id,
            );

            app(IntegrationAccountService::class)->setEnabled($account, false);
            $account->refresh();
            $this->assertFalse($account->enabled);

            $enabled = app(IntegrationAccountService::class)->listEnabled($owner, 'google');
            $this->assertFalse($enabled->contains(fn (IntegrationAccount $row): bool => $row->id === $account->id));

            Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response('', 200)]);
            app(SourceDeletionService::class)->removeGoogle($owner, $account->fresh());
            $this->assertSame(0, SourceItem::query()->where('integration_account_id', $account->id)->count());
            $this->assertSame('Finance', $project->fresh()->name);
            $this->assertSame(IntegrationAccountStatus::Disconnected, $account->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_calendar_aggregates_and_dedupes_shared_invitations(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner, 'google-sub-a', 'ceo@example.test', 'token-a', 'CEO');
            $this->connectGoogle($owner, 'google-sub-b', 'show@example.test', 'token-b', 'Show');
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake(function ($request) {
                if (! str_contains($request->url(), '/calendar/v3/calendars/')) {
                    return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
                }

                return Http::response([
                    'items' => [[
                        'id' => 'evt-shared',
                        'iCalUID' => 'shared-uid@google.com',
                        'summary' => 'Kickoff',
                        'start' => ['dateTime' => '2026-09-15T10:00:00+02:00'],
                        'end' => ['dateTime' => '2026-09-15T11:00:00+02:00'],
                        'organizer' => ['email' => 'host@example.test'],
                    ]],
                ], 200);
            });

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('c1', ListCalendarEventsTool::NAME, [
                    'time_min' => '2026-09-15T00:00:00',
                    'time_max' => '2026-09-16T00:00:00',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('ok', $result->payload['semantics']);
            $this->assertCount(1, $result->payload['events']);
            $this->assertSame('Kickoff', $result->payload['events'][0]['title']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_calendar_partial_account_failure_does_not_hide_healthy_events(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner, 'google-sub-ok', 'ok@example.test', 'token-ok', 'OK');
            $this->connectGoogle($owner, 'google-sub-bad', 'bad@example.test', 'token-bad', 'Bad');
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake(function ($request) {
                $auth = $this->httpAuthorization($request);
                if (str_contains($auth, 'token-bad')) {
                    return Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401);
                }

                return Http::response([
                    'items' => [[
                        'id' => 'evt-ok',
                        'summary' => 'Review',
                        'start' => ['dateTime' => '2026-09-15T12:00:00+02:00'],
                        'end' => ['dateTime' => '2026-09-15T13:00:00+02:00'],
                    ]],
                ], 200);
            });

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('c2', ListCalendarEventsTool::NAME, [
                    'time_min' => '2026-09-15T00:00:00',
                    'time_max' => '2026-09-16T00:00:00',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('partial', $result->payload['semantics']);
            $this->assertCount(1, $result->payload['events']);
            $this->assertNotEmpty($result->payload['unavailable']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_unknown_is_not_empty_when_no_mailboxes_work(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner, 'google-sub-x', 'blocked@example.test', 'token-x', 'Blocked');
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake(fn () => Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401));

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('u1', SearchEmailTool::NAME, ['query' => 'in:inbox']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('unknown', $result->payload['semantics']);
            $this->assertSame([], $result->payload['messages']);
            $this->assertNotEmpty($result->payload['unavailable']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    private function connectGoogle(
        User $owner,
        string $sub,
        string $email,
        string $token,
        string $label,
    ): IntegrationAccount {
        $accounts = app(IntegrationAccountService::class);
        $account = $accounts->upsertAccount(
            $owner,
            'google',
            $sub,
            $email,
            IntegrationAccountStatus::Connected,
            [
                'email',
                'openid',
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/calendar',
            ],
            null,
            $label,
        );
        $accounts->setCredentials($account, [
            'access_token' => $token,
            'refresh_token' => 'refresh-'.$token,
            'expires_at' => now()->addHour()->toIso8601String(),
            'token_type' => 'Bearer',
        ]);
        $accounts->markConnected($account);

        return $account->fresh() ?? $account;
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
