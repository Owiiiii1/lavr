<?php

namespace Tests\Feature\Voice;

use App\Enums\AiRoleKey;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\ToolConfirmationStatus;
use App\Enums\UserRole;
use App\Enums\VoiceSessionStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\ToolConfirmation;
use App\Models\VoiceSession;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Conversations\ConversationService;
use App\Services\Tools\CreateTaskTool;
use App\Services\Tools\Storage\DeleteStorageFileTool;
use App\Services\Voice\ElevenLabsRealtimeSessionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class ElevenLabsRealtimeVoiceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    private const SECRET = 'jarvis-custom-llm-test-secret';

    private const API_KEY = 'xi-test-key-must-not-leave-server';

    public function test_legacy_web_voice_session_store_does_not_call_elevenlabs_realtime(): void
    {
        $user = null;

        try {
            $this->enableRealtimeConfig();
            Http::preventStrayRequests();
            Http::fake();

            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'PTT');

            $response = $this->actingAs($user)->postJson(route('jarvis.voice.sessions.store', $conversation), [
                'origin' => 'web',
            ]);

            $response->assertCreated();
            $session = VoiceSession::query()->where('public_id', $response->json('public_id'))->first();
            $this->assertNotNull($session);
            $this->assertFalse($session->isRealtime());
            $this->assertSame($conversation->id, $session->conversation_id);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_realtime_session_binds_owned_conversation_and_hides_api_key(): void
    {
        $user = null;

        try {
            $this->enableRealtimeConfig();
            $this->fakeSignedUrl();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Beta');

            $response = $this->actingAs($user)->postJson(route('jarvis.voice.realtime.session.store', $conversation));

            $response->assertCreated();
            $json = $response->json();
            $this->assertArrayHasKey('public_id', $json);
            $this->assertArrayHasKey('signed_url', $json);
            $this->assertArrayHasKey('adapter_token', $json);
            $this->assertSame($conversation->id, $json['conversation_id']);
            $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
            $this->assertArrayNotHasKey('api_key', $json);
            $this->assertArrayNotHasKey('xi-api-key', $json);

            $session = VoiceSession::query()->where('public_id', $json['public_id'])->first();
            $this->assertTrue($session->isRealtime());
            $this->assertSame(ElevenLabsRealtimeSessionService::PROVIDER, $session->meta()['provider']);
            $this->assertSame($user->id, $session->user_id);
            $this->assertSame($conversation->id, $session->conversation_id);
            $this->assertSame(VoiceSessionStatus::Listening, $session->status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_foreign_conversation_is_denied_for_realtime_session(): void
    {
        $userA = null;
        $userB = null;

        try {
            $this->enableRealtimeConfig();
            $this->fakeSignedUrl();
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $foreign = app(ConversationService::class)->createPersonal($userB, 'Secret');

            $this->actingAs($userA)
                ->postJson(route('jarvis.voice.realtime.session.store', $foreign))
                ->assertNotFound();

            $this->assertSame(0, VoiceSession::query()->where('user_id', $userA->id)->count());
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_realtime_disabled_config_fails_safely(): void
    {
        $user = null;

        try {
            config([
                'voice.realtime.enabled' => false,
                'voice.realtime.agent_id' => 'agent_test',
                'voice.realtime.custom_llm_secret' => self::SECRET,
                'voice.elevenlabs.api_key' => self::API_KEY,
            ]);
            Http::preventStrayRequests();
            Http::fake();

            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Off');

            $this->actingAs($user)
                ->postJson(route('jarvis.voice.realtime.session.store', $conversation))
                ->assertStatus(503)
                ->assertJsonPath('error', 'voice_realtime_not_configured');

            Http::assertNothingSent();
            $this->assertSame(0, VoiceSession::query()->where('conversation_id', $conversation->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_custom_llm_adapter_resolves_local_session_and_ignores_arbitrary_ids(): void
    {
        $user = null;
        $other = null;
        $fake = new FakeAiChatGateway;
        $fake->responseText = 'Realtime assistant reply';

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);
            $this->enableRealtimeConfig();
            $this->fakeSignedUrl();

            $user = $this->createTemporaryUser();
            $other = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Bound');
            $foreign = app(ConversationService::class)->createPersonal($other, 'Foreign');

            $start = $this->actingAs($user)->postJson(route('jarvis.voice.realtime.session.store', $conversation));
            $start->assertCreated();
            $token = $start->json('adapter_token');

            $response = $this->withToken(self::SECRET)->postJson('/api/voice/elevenlabs/chat/completions', [
                'messages' => [
                    ['role' => 'system', 'content' => 'ElevenLabs system'],
                    ['role' => 'user', 'content' => 'Привет. Давай обсудим Jarvis.'],
                ],
                'user' => (string) $other->id,
                'conversation_id' => $foreign->id,
                'elevenlabs_extra_body' => [
                    'jarvis_session_token' => $token,
                    'user_id' => $other->id,
                    'conversation_id' => $foreign->id,
                ],
            ]);

            $response->assertOk();
            $content = $this->streamed($response);
            $this->assertStringContainsString('Realtime assistant reply', $content);
            $this->assertStringContainsString('data: [DONE]', $content);

            $inbound = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', MessageRole::User)
                ->first();
            $assistant = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', MessageRole::Assistant)
                ->first();

            $this->assertNotNull($inbound);
            $this->assertNotNull($assistant);
            $this->assertSame(MessageChannel::Web, $inbound->channel);
            $this->assertSame('voice', $inbound->metadata['modality']);
            $this->assertSame('realtime', $inbound->metadata['voice_mode']);
            $this->assertSame('Привет. Давай обсудим Jarvis.', $inbound->body);
            $this->assertSame('Realtime assistant reply', $assistant->body);
            $this->assertSame('voice', $assistant->metadata['modality']);
            $this->assertSame('realtime', $assistant->metadata['voice_mode']);
            $this->assertSame(0, Message::query()->where('conversation_id', $foreign->id)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_adapter_without_session_token_returns_401(): void
    {
        $this->enableRealtimeConfig();

        $this->withToken(self::SECRET)->postJson('/api/voice/elevenlabs/chat/completions', [
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'conversation_id' => 1,
            'user_id' => 1,
        ])->assertUnauthorized();
    }

    public function test_adapter_rejects_wrong_bearer_secret(): void
    {
        $this->enableRealtimeConfig();

        $this->withToken('wrong-secret')->postJson('/api/voice/elevenlabs/chat/completions', [
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'elevenlabs_extra_body' => ['jarvis_session_token' => 'nope'],
        ])->assertUnauthorized();
    }

    public function test_switching_chat_ends_previous_realtime_session(): void
    {
        $user = null;

        try {
            $this->enableRealtimeConfig();
            $this->fakeSignedUrl();
            $user = $this->createTemporaryUser();
            $first = app(ConversationService::class)->createPersonal($user, 'One');
            $second = app(ConversationService::class)->createPersonal($user, 'Two');

            $a = $this->actingAs($user)->postJson(route('jarvis.voice.realtime.session.store', $first));
            $a->assertCreated();
            $oldId = $a->json('public_id');

            $b = $this->actingAs($user)->postJson(route('jarvis.voice.realtime.session.store', $second));
            $b->assertCreated();

            $old = VoiceSession::query()->where('public_id', $oldId)->first();
            $this->assertSame(VoiceSessionStatus::Ended, $old->status);
            $this->assertSame($first->id, $old->conversation_id);

            $fresh = VoiceSession::query()->where('public_id', $b->json('public_id'))->first();
            $this->assertSame($second->id, $fresh->conversation_id);
            $this->assertTrue($fresh->isRealtime());
            $this->assertNotSame($old->public_id, $fresh->public_id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_ending_realtime_voice_does_not_delete_conversation(): void
    {
        $user = null;

        try {
            $this->enableRealtimeConfig();
            $this->fakeSignedUrl();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Keep');

            $start = $this->actingAs($user)->postJson(route('jarvis.voice.realtime.session.store', $conversation));
            $start->assertCreated();

            $this->actingAs($user)
                ->deleteJson(route('jarvis.voice.realtime.session.destroy', $start->json('public_id')))
                ->assertOk()
                ->assertJsonPath('status', VoiceSessionStatus::Ended->value);

            $this->assertTrue(Conversation::query()->whereKey($conversation->id)->exists());
            $this->assertSame('Keep', $conversation->fresh()->title);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_workspace_exposes_fallback_to_ptt_and_realtime_flags(): void
    {
        $user = null;

        try {
            $this->enableRealtimeConfig();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Flags');

            $this->actingAs($user)
                ->get(route('jarvis.chats.show', $conversation))
                ->assertOk();

            $payload = app(ElevenLabsRealtimeSessionService::class)->workspacePayload();
            $this->assertTrue($payload['configured']);
            $this->assertSame('ptt', $payload['default_mode']);
            $this->assertSame('Переключиться на Рацию', $payload['fallback_label']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_confirmation_policy_still_enforced_on_realtime_turns(): void
    {
        $user = null;
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $fake->queueToolThenText(DeleteStorageFileTool::NAME, [
                'file_id' => (string) Str::uuid(),
            ], 'Нужно подтверждение, чтобы удалить файл.');
            $this->app->instance(AiChatGateway::class, $fake);
            $this->enableRealtimeConfig();
            $this->fakeSignedUrl();

            $user = $this->createTemporaryUser();
            $user->forceFill(['role' => UserRole::Owner])->save();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Confirm');

            $start = $this->actingAs($user)->postJson(route('jarvis.voice.realtime.session.store', $conversation));
            $token = $start->json('adapter_token');

            $this->withToken(self::SECRET)->postJson('/api/voice/elevenlabs/chat/completions', [
                'messages' => [
                    ['role' => 'user', 'content' => 'Удали этот файл из storage'],
                ],
                'elevenlabs_extra_body' => ['jarvis_session_token' => $token],
            ])->assertOk();

            $this->assertNotNull(
                ToolConfirmation::query()
                    ->where('user_id', $user->id)
                    ->where('status', ToolConfirmationStatus::Pending)
                    ->first(),
            );
            $assistant = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', MessageRole::Assistant)
                ->first();
            $this->assertNotNull($assistant);
            $this->assertNotEmpty($assistant->metadata['pending_confirmation'] ?? null);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_realtime_turn_can_create_a_core_task(): void
    {
        $user = null;
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $fake->queueToolThenText(CreateTaskTool::NAME, [
                'title' => 'Купить фильтр',
            ], 'Задача создана.');
            $this->app->instance(AiChatGateway::class, $fake);
            $this->enableRealtimeConfig();
            $this->fakeSignedUrl();

            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Task');
            $token = $this->actingAs($user)
                ->postJson(route('jarvis.voice.realtime.session.store', $conversation))
                ->json('adapter_token');

            $this->withToken(self::SECRET)->postJson('/api/voice/elevenlabs/chat/completions', [
                'messages' => [['role' => 'user', 'content' => 'Создай задачу купить фильтр']],
                'elevenlabs_extra_body' => ['jarvis_session_token' => $token],
            ])->assertOk();

            $this->assertSame(1, Task::query()->where('user_id', $user->id)->where('title', 'Купить фильтр')->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_unauthenticated_adapter_cannot_call_core(): void
    {
        $this->enableRealtimeConfig();

        $this->postJson('/api/voice/elevenlabs/chat/completions', [
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ])->assertUnauthorized();
    }

    private function enableRealtimeConfig(): void
    {
        config([
            'voice.realtime.enabled' => true,
            'voice.realtime.agent_id' => 'agent_test_123',
            'voice.realtime.custom_llm_secret' => self::SECRET,
            'voice.elevenlabs.api_key' => self::API_KEY,
        ]);
    }

    private function fakeSignedUrl(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*convai/conversation/get-signed-url*' => Http::response([
                'signed_url' => 'https://api.elevenlabs.io/v1/convai/conversation?token=ephemeral-test',
            ], 200),
        ]);
    }

    private function streamed($response): string
    {
        if (method_exists($response, 'streamedContent')) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }
}
