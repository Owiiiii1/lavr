<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\MessageRole;
use App\Enums\UserRole;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TelegramBotSetting;
use App\Models\UserAiSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Conversations\ConversationAiService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class AiRuntimeTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_ai_role_and_user_prompt_schema(): void
    {
        $this->assertTrue(Schema::hasTable('ai_role_settings'));
        $this->assertTrue(Schema::hasColumns('ai_role_settings', [
            'role_key', 'provider', 'model', 'system_prompt', 'parameters', 'is_enabled',
        ]));
        $this->assertTrue(Schema::hasTable('user_ai_settings'));
        $this->assertTrue(Schema::hasColumns('user_ai_settings', [
            'user_id', 'general_prompt', 'overrides',
        ]));
        $this->assertTrue(Schema::hasColumn('messages', 'parent_message_id'));

        $this->assertSame(3, AiRoleSetting::query()->count());
        $this->assertSame(
            [AiRoleKey::OwnerConversation->value, AiRoleKey::OwnerAnalysis->value, AiRoleKey::UserConversation->value],
            AiRoleSetting::query()->orderBy('id')->pluck('role_key')->map(fn ($key) => $key instanceof AiRoleKey ? $key->value : $key)->all()
        );
    }

    public function test_inbound_persists_before_assistant_and_duplicate_does_not_recall_ai(): void
    {
        $user = null;
        $externalUserId = '930101';
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            UserAiSetting::query()->create([
                'user_id' => $user->id,
                'general_prompt' => 'Always answer with the word banana.',
            ]);
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, '/start', 930101);
            $this->postTelegramUpdate($externalUserId, 'Привет', 930102, messageId: 41);
            $this->postTelegramUpdate($externalUserId, 'Привет', 930102, messageId: 41);

            $conversation = Conversation::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($conversation);
            $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::User)->count());
            $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->count());
            $this->assertSame('Fake assistant reply', Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->value('body'));

            $inbound = Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::User)->first();
            $assistant = Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->first();
            $this->assertSame($inbound->id, $assistant->parent_message_id);
            $this->assertSame(AiRoleKey::UserConversation->value, $assistant->metadata['ai']['configuration'] ?? null);
            $this->assertSame(1, count($fake->conversationCalls()));
            $this->assertSame(AiRoleKey::UserConversation->value, $fake->conversationCalls()[0]['role_key']);
            $this->assertStringContainsString('Always answer with the word banana.', $fake->calls[0]['request']->systemPrompt);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_transient_empty_provider_response_is_retried(): void
    {
        $user = null;
        $externalUserId = '930108';
        $fake = new FakeAiChatGateway;
        Sleep::fake();
        $fake->script = [
            static function (): never {
                throw new AiEmptyResponseException;
            },
            new AiChatResponse(
                text: 'Второй заход удался.',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            ),
        ];

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, 'Привет ещё раз', 930108, messageId: 61);

            $this->assertSame(
                'Второй заход удался.',
                Message::query()->where('user_id', $user->id)->where('role', MessageRole::Assistant)->value('body'),
            );
            $this->assertSame(2, count($fake->conversationCalls()));
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_invalid_api_key_is_not_retried(): void
    {
        $user = null;
        $externalUserId = '930109';
        $fake = new FakeAiChatGateway;
        Sleep::fake();
        $fake->script = [
            static function (): never {
                throw new AiProviderException('API key not valid');
            },
            new AiChatResponse(
                text: 'This must not be used.',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            ),
        ];

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, 'Hello auth', 930109, messageId: 62);

            $this->assertSame(1, count($fake->conversationCalls()));
            $this->assertSame(
                ConversationAiService::AI_FAILURE,
                Message::query()->where('user_id', $user->id)->where('role', MessageRole::System)->value('body'),
            );
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_ai_failure_keeps_inbound_without_assistant_reply(): void
    {
        $user = null;
        $externalUserId = '930102';
        $fake = new FakeAiChatGateway;
        $fake->exception = new AiProviderException('upstream failed');

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, 'Hello failure', 930201, messageId: 55);

            $this->assertSame(1, Message::query()->where('user_id', $user->id)->where('role', MessageRole::User)->count());
            $this->assertSame(0, Message::query()->where('user_id', $user->id)->where('role', MessageRole::Assistant)->count());
            $this->assertSame(1, Message::query()->where('user_id', $user->id)->where('role', MessageRole::System)->count());
            $this->assertSame(
                ConversationAiService::AI_FAILURE,
                Message::query()->where('user_id', $user->id)->where('role', MessageRole::System)->value('body')
            );
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_pairing_greeting_uses_user_conversation_config(): void
    {
        $user = null;
        $externalUserId = '930103';
        $fake = new FakeAiChatGateway;
        $fake->responseText = 'Welcome to Jarvis.';

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->postTelegramUpdate($externalUserId, $user->access_code, 930301, messageId: 9);

            $assistant = Message::query()
                ->where('user_id', $user->id)
                ->where('role', MessageRole::Assistant)
                ->first();

            $this->assertNotNull($assistant);
            $this->assertSame('Welcome to Jarvis.', $assistant->body);
            $this->assertSame(AiRoleKey::UserConversation->value, $assistant->metadata['ai']['configuration'] ?? null);
            $this->assertSame('pairing_greeting', $assistant->metadata['ai']['event'] ?? null);
            $this->assertSame(1, count($fake->conversationCalls()));
            $this->assertStringContainsString('Пользователь только что подключил LAVR', $fake->calls[0]['request']->systemPrompt);
            $this->assertSame(0, Message::query()->where('user_id', $user->id)->where('role', MessageRole::User)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_conversation_config_is_not_used_for_users(): void
    {
        $user = null;
        $externalUserId = '930104';
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerConversation, 'anthropic', 'claude-fake');
            $this->enableRoleForTests(AiRoleKey::UserConversation, 'openai', 'gpt-fake');
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, 'Ping', 930401, messageId: 12);

            $this->assertSame(AiRoleKey::UserConversation->value, $fake->calls[0]['role_key']);
            $this->assertNotSame(AiRoleKey::OwnerConversation->value, $fake->calls[0]['role_key']);
            $this->assertNotSame(AiRoleKey::OwnerAnalysis->value, $fake->calls[0]['role_key']);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_turn_uses_owner_conversation_config(): void
    {
        $user = null;
        $externalUserId = '930105';
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerConversation, 'openai', 'owner-model');
            $this->enableRoleForTests(AiRoleKey::UserConversation, 'openai', 'user-model');
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $user->forceFill(['role' => UserRole::Owner])->save();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, 'Owner ping', 930501, messageId: 13);

            $this->assertSame(AiRoleKey::OwnerConversation->value, $fake->calls[0]['role_key']);
            $this->assertSame('owner-model', $fake->calls[0]['model']);
        } finally {
            if ($user !== null) {
                $user->forceFill(['role' => UserRole::User])->save();
            }
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_tool_loop_limit_requests_a_final_text_answer_without_tools(): void
    {
        $user = null;
        $externalUserId = '930110';
        $fake = new FakeAiChatGateway;
        $loop = new AiChatResponse(
            text: '',
            provider: 'fake',
            model: 'fake-model',
            finishReason: 'tool_calls',
            toolCalls: [new ToolCall('loop', 'search_conversation_history', ['query' => 'axis'])],
        );
        $fake->script = array_fill(0, ConversationAiService::MAX_TOOL_ROUNDS + 1, $loop);
        $fake->script[] = new AiChatResponse(
            text: 'По файлу ось A сдвигается на 0.02 на программу.',
            provider: 'fake',
            model: 'fake-model',
            finishReason: 'stop',
        );

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);
            config(['context_budget.no_progress_tool_rounds' => 99]);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, 'Посчитай сдвиг оси A', 930110, messageId: 70);

            $calls = $fake->conversationCalls();
            $this->assertSame(ConversationAiService::MAX_TOOL_ROUNDS + 2, count($calls));
            $this->assertSame([], $calls[array_key_last($calls)]['request']->tools);
            $this->assertSame(
                'По файлу ось A сдвигается на 0.02 на программу.',
                Message::query()->where('user_id', $user->id)->where('role', MessageRole::Assistant)->value('body'),
            );
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_user_cannot_modify_another_users_prompt_or_admin_ai_config(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();

            UserAiSetting::query()->create([
                'user_id' => $userB->id,
                'general_prompt' => 'Keep this prompt.',
            ]);

            $this->actingAs($userA)
                ->patch('/cabinet/ai-settings', ['general_prompt' => 'Prompt for A'])
                ->assertRedirect();

            $this->assertSame('Prompt for A', UserAiSetting::query()->where('user_id', $userA->id)->value('general_prompt'));
            $this->assertSame('Keep this prompt.', UserAiSetting::query()->where('user_id', $userB->id)->value('general_prompt'));

            $this->actingAs($userA)->get('/settings?tab=ai')->assertForbidden();
            $this->actingAs($userA)->patch('/ai-settings/roles/user_conversation', [
                'system_prompt' => 'hijack',
                'is_enabled' => false,
            ])->assertForbidden();
            $this->actingAs($userA)->get('/cabinet/ai-settings')->assertRedirect(route('jarvis.index'));
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    private function webhookSecret(): string
    {
        $setting = TelegramBotSetting::query()->first();
        $this->assertNotNull($setting);
        $this->assertNotEmpty($setting->webhook_secret);

        return (string) $setting->webhook_secret;
    }

    private function postTelegramUpdate(string $externalUserId, string $text, int $updateId, int $messageId = 1): void
    {
        $this->postJson('/telegram/webhook', [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => (int) $externalUserId, 'type' => 'private', 'first_name' => 'Test'],
                'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'text' => $text,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
        ])->assertOk();
    }
}
