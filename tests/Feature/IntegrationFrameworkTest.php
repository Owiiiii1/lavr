<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\IntegrationAccountStatus;
use App\Enums\MessageChannel;
use App\Enums\ToolConfirmationDecision;
use App\Enums\ToolExecutionLogStatus;
use App\Enums\ToolOperationClass;
use App\Enums\UserRole;
use App\Models\IntegrationAccount;
use App\Models\ToolExecutionLog;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\SearchGroupKnowledgeTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use App\Services\Users\UserCapability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\FakeIntegrationTool;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class IntegrationFrameworkTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_schema_and_registry_exist(): void
    {
        $this->assertTrue(Schema::hasTable('integration_accounts'));
        $this->assertTrue(Schema::hasTable('tool_execution_logs'));
        $this->assertTrue(Schema::hasColumns('integration_accounts', [
            'user_id',
            'provider',
            'external_account_id',
            'status',
            'credentials_encrypted',
        ]));

        $keys = array_map(
            static fn ($provider): string => $provider->key(),
            app(IntegrationRegistry::class)->all(),
        );
        $this->assertSame(['google', 'telegram', 'elevenlabs', 'github', 'zoom'], $keys);
    }

    public function test_owner_integrations_page_and_user_denied(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();

            $ownerResponse = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $ownerResponse->assertOk();
            $html = $ownerResponse->getContent();
            $this->assertStringContainsString('Google', $html);
            $this->assertStringContainsString('Telegram', $html);
            $this->assertStringContainsString('ElevenLabs', $html);
            $this->assertStringContainsString('GitHub', $html);
            $this->assertStringContainsString('Not configured', $html);
            $this->assertStringContainsString('has_bot_token', $html);
            $this->assertStringNotContainsString('Open Telegram settings', $html);
            $this->assertStringNotContainsString('credentials_encrypted', $html);
            $this->assertStringNotContainsString('access_token', $html);

            $this->actingAs($owner)->get(route('settings.integrations.index'))
                ->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $this->actingAs($user)->get(route('settings.index', ['tab' => 'integrations']))->assertForbidden();
            $this->actingAs($user)->get(route('settings.integrations.index'))->assertForbidden();

            $legacyTelegramTab = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'telegram']));
            $legacyTelegramTab->assertOk();
            $this->assertStringContainsString('"tab":"integrations"', $legacyTelegramTab->getContent());
            $this->assertStringContainsString('has_bot_token', $legacyTelegramTab->getContent());

            $this->actingAs($owner)
                ->from(route('settings.index', ['tab' => 'integrations']))
                ->post(route('settings.telegram.save-token'), [])
                ->assertRedirect(route('settings.index', ['tab' => 'integrations']))
                ->assertSessionHasErrors('bot_token');
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_credentials_encrypted_and_never_serialized(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $secret = 'SYNTHETIC-M16-TEST-SECRET';
            $accounts = app(IntegrationAccountService::class);
            $account = $accounts->upsertAccount(
                $owner,
                'google',
                status: IntegrationAccountStatus::Connecting,
            );
            $accounts->setCredentials($account, ['access_token' => $secret, 'refresh_token' => $secret.'-refresh']);

            $raw = DB::table('integration_accounts')->where('id', $account->id)->value('credentials_encrypted');
            $this->assertIsString($raw);
            $this->assertNotSame('', $raw);
            $this->assertStringNotContainsString($secret, (string) $raw);

            $account->refresh();
            $this->assertSame($secret, $accounts->getCredentials($account)['access_token']);
            $this->assertArrayNotHasKey('credentials_encrypted', $account->toArray());
            $this->assertStringNotContainsString($secret, (string) json_encode($account));

            $page = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $page->assertOk();
            $this->assertStringNotContainsString($secret, $page->getContent());
            $this->assertStringNotContainsString((string) $raw, $page->getContent());

            $safe = $this->actingAs($owner)->getJson(route('settings.integrations.accounts.show', $account));
            $safe->assertOk();
            $safe->assertJsonMissingPath('credentials_encrypted');
            $this->assertStringNotContainsString($secret, $safe->getContent());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_user_cannot_inspect_account_or_receive_integration_tools(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();
            $account = app(IntegrationAccountService::class)->upsertAccount(
                $owner,
                'google',
                status: IntegrationAccountStatus::Disconnected,
            );
            $chat = app(ConversationService::class)->createPersonal($user, 'Основной');
            $ownerChat = app(ConversationService::class)->createPersonal($owner, 'Основной');

            $this->actingAs($user)->getJson(route('settings.integrations.accounts.show', $account))->assertForbidden();

            $registry = app(ToolRegistry::class);
            $registry->register(new FakeIntegrationTool(
                toolName: 'fake_calendar_lookup',
                capability: UserCapability::GOOGLE_CALENDAR,
                provider: 'google',
            ));

            $userTools = array_map(
                static fn ($tool) => $tool->name,
                $registry->definitionsFor(new ToolExecutionContext($user, $chat)),
            );
            $ownerTools = array_map(
                static fn ($tool) => $tool->name,
                $registry->definitionsFor(new ToolExecutionContext($owner, $ownerChat)),
            );

            $this->assertNotContains('fake_calendar_lookup', $userTools);
            $this->assertContains('fake_calendar_lookup', $ownerTools);
            $this->assertContains(CreateReminderTool::NAME, $userTools);
            $this->assertContains(SearchConversationHistoryTool::NAME, $userTools);
            $this->assertNotContains(GetProjectContextTool::NAME, $userTools);
            $this->assertNotContains(SearchGroupKnowledgeTool::NAME, $userTools);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_telegram_is_virtual_and_placeholders_are_disconnected(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $summaries = app(IntegrationRegistry::class)->summariesForOwner($owner);
            $byKey = [];
            foreach ($summaries as $summary) {
                $byKey[$summary['provider']] = $summary;
            }

            $this->assertSame('disconnected', $byKey['google']['state']);
            $this->assertSame('disconnected', $byKey['elevenlabs']['state']);
            $this->assertArrayHasKey('telegram', $byKey);
            $this->assertSame(0, IntegrationAccount::query()->where('user_id', $owner->id)->count());
            $this->assertSame(0, IntegrationAccount::query()->where('provider', 'telegram')->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_execution_logging_success_failure_denied_and_duration(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();
            $ownerChat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $userChat = app(ConversationService::class)->createPersonal($user, 'Основной');
            $accounts = app(IntegrationAccountService::class);
            $account = $accounts->upsertAccount($owner, 'google', status: IntegrationAccountStatus::Connected);
            $accounts->markConnected($account);

            $registry = app(ToolRegistry::class);
            $registry->register(new FakeIntegrationTool(
                toolName: 'fake_calendar_lookup',
                provider: 'google',
                includeSecret: true,
            ));
            $registry->register(new FakeIntegrationTool(
                toolName: 'fake_calendar_fail',
                provider: 'google',
                fail: true,
            ));

            $ok = $registry->execute(
                new ToolCall('s1', 'fake_calendar_lookup', ['query' => 'meetings', 'authorized' => true]),
                new ToolExecutionContext($owner, $ownerChat, explicitUserCommand: true),
            );
            $this->assertTrue($ok->success);

            $failed = $registry->execute(
                new ToolCall('s2', 'fake_calendar_fail', ['query' => 'x']),
                new ToolExecutionContext($owner, $ownerChat, explicitUserCommand: true),
            );
            $this->assertFalse($failed->success);

            $denied = $registry->execute(
                new ToolCall('s3', 'fake_calendar_lookup', ['query' => 'x']),
                new ToolExecutionContext($user, $userChat),
            );
            $this->assertSame('tool_not_available', $denied->payload['error']);

            $logs = ToolExecutionLog::query()->where('user_id', $owner->id)->orderBy('id')->get();
            $this->assertCount(2, $logs);
            $this->assertSame(ToolExecutionLogStatus::Succeeded, $logs[0]->status);
            $this->assertSame(ToolExecutionLogStatus::Failed, $logs[1]->status);
            $this->assertNotNull($logs[0]->duration_ms);
            $this->assertSame($account->id, $logs[0]->integration_account_id);
            $this->assertArrayNotHasKey('access_token', $logs[0]->metadata ?? []);
            $this->assertStringNotContainsString('SHOULD-NEVER-BE-LOGGED', json_encode($logs[0]->metadata));

            $deniedLog = ToolExecutionLog::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($deniedLog);
            $this->assertSame(ToolExecutionLogStatus::Denied, $deniedLog->status);

            $account->refresh();
            $this->assertNotNull($account->last_success_at);
            $this->assertNotNull($account->last_error_at);
            $this->assertSame('fake_failed', $account->last_error_code);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_confirmation_policy_and_model_cannot_self_authorize(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $registry = app(ToolRegistry::class);
            $registry->register(new FakeIntegrationTool('fake_calendar_lookup', ToolOperationClass::Read, provider: 'google'));
            $registry->register(new FakeIntegrationTool('fake_calendar_create', ToolOperationClass::Write, provider: 'google'));
            $registry->register(new FakeIntegrationTool('fake_calendar_delete', ToolOperationClass::Destructive, provider: 'google'));

            $read = $registry->execute(
                new ToolCall('r1', 'fake_calendar_lookup', []),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: false),
            );
            $this->assertTrue($read->success);

            $explicitWrite = $registry->execute(
                new ToolCall('w1', 'fake_calendar_create', []),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($explicitWrite->success);

            $proposedWrite = $registry->execute(
                new ToolCall('w2', 'fake_calendar_create', ['authorized' => true, 'confirmation' => true]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: false),
            );
            $this->assertFalse($proposedWrite->success);
            $this->assertSame('confirmation_required', $proposedWrite->payload['error']);

            $destructive = $registry->execute(
                new ToolCall('d1', 'fake_calendar_delete', ['authorized' => true]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertSame('confirmation_required', $destructive->payload['error']);

            $confirmLog = ToolExecutionLog::query()
                ->where('user_id', $owner->id)
                ->where('tool_name', 'fake_calendar_create')
                ->where('status', ToolExecutionLogStatus::ConfirmationRequired)
                ->first();
            $this->assertNotNull($confirmLog);
            $this->assertSame(ToolConfirmationDecision::ConfirmationRequired, $confirmLog->confirmation_state);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_core_tools_use_null_account_and_keep_definitions(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();
            $ownerChat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $userChat = app(ConversationService::class)->createPersonal($user, 'Основной');
            $registry = app(ToolRegistry::class);

            $ownerTools = array_map(
                static fn ($tool) => $tool->name,
                $registry->definitionsFor(new ToolExecutionContext($owner, $ownerChat)),
            );
            $userTools = array_map(
                static fn ($tool) => $tool->name,
                $registry->definitionsFor(new ToolExecutionContext($user, $userChat)),
            );

            foreach ([
                CreateReminderTool::NAME,
                SearchConversationHistoryTool::NAME,
                GetProjectContextTool::NAME,
                SearchGroupKnowledgeTool::NAME,
                'list_google_calendars',
                'list_meetings',
                'get_meeting_analysis',
            ] as $name) {
                $this->assertContains($name, $ownerTools);
            }
            $this->assertContains(CreateReminderTool::NAME, $userTools);
            $this->assertContains(SearchConversationHistoryTool::NAME, $userTools);

            $history = $registry->execute(
                new ToolCall('h1', SearchConversationHistoryTool::NAME, ['query' => 'nothing-here-m16']),
                new ToolExecutionContext($owner, $ownerChat, explicitUserCommand: true),
            );
            $this->assertTrue($history->success);

            $log = ToolExecutionLog::query()
                ->where('user_id', $owner->id)
                ->where('tool_name', SearchConversationHistoryTool::NAME)
                ->latest('id')
                ->first();
            $this->assertNotNull($log);
            $this->assertNull($log->integration_account_id);
            $this->assertNull($log->provider);
            $this->assertSame(ToolExecutionLogStatus::Succeeded, $log->status);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_exception_is_safe_and_disconnect_is_local(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $accounts = app(IntegrationAccountService::class);
            $account = $accounts->upsertAccount($owner, 'google', status: IntegrationAccountStatus::Connected);
            $accounts->setCredentials($account, ['access_token' => 'SYNTHETIC-DISCONNECT']);
            $accounts->markConnected($account);

            $registry = app(ToolRegistry::class);
            $registry->register(new FakeIntegrationTool(
                toolName: 'fake_calendar_boom',
                provider: 'google',
                throw: new \RuntimeException('raw provider body with SYNTHETIC-DISCONNECT'),
            ));

            $result = $registry->execute(
                new ToolCall('b1', 'fake_calendar_boom', []),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertFalse($result->success);
            $this->assertSame('tool_failed', $result->payload['error']);
            $this->assertArrayNotHasKey('message', $result->payload);

            $accounts->disconnect($account->fresh());
            $account->refresh();
            $this->assertSame(IntegrationAccountStatus::Disconnected, $account->status);
            $this->assertSame([], $accounts->getCredentials($account));
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_multi_step_loop_can_call_two_tools(): void
    {
        $owner = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerConversation);
            $fake = new FakeAiChatGateway;
            $this->app->forgetInstance(AiChatGateway::class);
            $this->app->instance(AiChatGateway::class, $fake);

            $owner = $this->temporaryOwner();
            $conversation = app(ConversationService::class)->createPersonal($owner, 'Основной');
            app(ToolRegistry::class)->register(new FakeIntegrationTool('fake_calendar_lookup', provider: 'google'));
            app(ToolRegistry::class)->register(new FakeIntegrationTool('fake_calendar_create', ToolOperationClass::Write, provider: 'google'));

            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('a1', 'fake_calendar_lookup', ['query' => 'today'])],
            );
            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('a2', SearchConversationHistoryTool::NAME, ['query' => 'm16-loop'])],
            );
            $fake->script[] = new AiChatResponse(
                text: 'Two tools completed',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            );

            $turn = app(ConversationTurnService::class)->handleUserMessage(
                $owner,
                $conversation,
                'Check groups and old chats',
                new ChannelContext(MessageChannel::Web, 'm16-web-1'),
            );

            $this->assertSame('Two tools completed', $turn->assistantMessage?->body);
            $this->assertGreaterThanOrEqual(3, count($fake->conversationCalls()));
            $this->assertSame(2, ToolExecutionLog::query()->where('user_id', $owner->id)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
