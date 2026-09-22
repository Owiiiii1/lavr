<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TelegramBotSetting;
use App\Models\UserAiSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Conversations\ConversationAiService;
use App\Services\Conversations\ConversationService;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class CabinetChatTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_user_sees_own_chats_and_cannot_open_foreign(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $service = app(ConversationService::class);
            $own = $service->createPersonal($userA, 'Mine');
            $foreign = $service->createPersonal($userB, 'Secret');

            $service->getOrCreateDefault($userA);

            $this->actingAs($userA)->get('/cabinet')->assertRedirect(route('jarvis.index'));
            $this->actingAs($userA)->get('/cabinet/chats/'.$own->id)
                ->assertRedirect(route('jarvis.chats.show', $own->id));

            $this->actingAs($userA)->get(route('jarvis.chats.show', $own->id))->assertOk()->assertSee('Mine');
            $this->actingAs($userA)->get(route('jarvis.chats.show', $foreign->id))->assertNotFound();
            $this->actingAs($userA)->getJson('/cabinet/chats/'.$own->id.'/messages')->assertOk();
            $this->actingAs($userA)->getJson('/cabinet/chats/'.$foreign->id.'/messages')->assertNotFound();
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_create_and_rename_own_chat_but_not_foreign(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $foreign = app(ConversationService::class)->createPersonal($userB, 'Keep');

            $this->actingAs($userA)->post('/cabinet/chats')->assertRedirect();
            $created = Conversation::query()->where('user_id', $userA->id)->where('title', ConversationService::NEW_CHAT_TITLE)->first();
            $this->assertNotNull($created);
            $this->actingAs($userA)->post('/cabinet/chats')
                ->assertRedirect(route('jarvis.chats.show', Conversation::query()->where('user_id', $userA->id)->latest('id')->value('id')));

            $this->actingAs($userA)->patch('/cabinet/chats/'.$created->id, ['title' => 'Работа'])->assertRedirect();
            $this->assertSame('Работа', $created->fresh()->title);

            $this->actingAs($userA)->patch('/cabinet/chats/'.$foreign->id, ['title' => 'Hijack'])->assertNotFound();
            $this->assertSame('Keep', $foreign->fresh()->title);
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_web_message_persists_with_user_conversation_and_idempotency(): void
    {
        $user = null;
        $fake = new FakeAiChatGateway;
        $fake->responseText = 'Web assistant reply';

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            UserAiSetting::query()->create([
                'user_id' => $user->id,
                'general_prompt' => 'Always mention pineapples.',
            ]);
            $conversation = app(ConversationService::class)->getOrCreateDefault($user);
            $clientId = (string) Str::uuid();

            $first = $this->actingAs($user)->postJson('/cabinet/chats/'.$conversation->id.'/messages', [
                'body' => 'Hello from web',
                'client_message_id' => $clientId,
            ]);
            $first->assertOk();

            $second = $this->actingAs($user)->postJson('/cabinet/chats/'.$conversation->id.'/messages', [
                'body' => 'Hello from web',
                'client_message_id' => $clientId,
            ]);
            $second->assertOk();
            $this->assertTrue((bool) $second->json('duplicate'));

            $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::User)->count());
            $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->count());
            $inbound = Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::User)->first();
            $this->assertSame(MessageChannel::Web, $inbound->channel);
            $this->assertSame($clientId, $inbound->channel_message_id);
            $assistant = Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->first();
            $this->assertSame('Web assistant reply', $assistant->body);
            $this->assertSame(AiRoleKey::UserConversation->value, $assistant->metadata['ai']['configuration'] ?? null);
            $this->assertSame(1, count($fake->conversationCalls()));
            $this->assertStringContainsString('Always mention pineapples.', $fake->calls[0]['request']->systemPrompt);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_web_ai_failure_does_not_create_assistant(): void
    {
        $user = null;
        $fake = new FakeAiChatGateway;
        $fake->exception = new AiProviderException('upstream failed');

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->getOrCreateDefault($user);

            $this->actingAs($user)->postJson('/cabinet/chats/'.$conversation->id.'/messages', [
                'body' => 'Will fail',
                'client_message_id' => (string) Str::uuid(),
            ])->assertOk()->assertJsonPath('error', ConversationAiService::AI_FAILURE);

            $this->assertSame(1, Message::query()->where('user_id', $user->id)->where('role', MessageRole::User)->count());
            $this->assertSame(0, Message::query()->where('user_id', $user->id)->where('role', MessageRole::Assistant)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_telegram_and_web_share_conversation_history(): void
    {
        $user = null;
        $externalUserId = '930201';
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $secret = TelegramBotSetting::query()->first()->webhook_secret;

            $this->postJson('/telegram/webhook', [
                'update_id' => 930201,
                'message' => [
                    'message_id' => 21,
                    'date' => time(),
                    'chat' => ['id' => (int) $externalUserId, 'type' => 'private', 'first_name' => 'Test'],
                    'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                    'text' => 'From Telegram',
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $secret,
            ])->assertOk();

            $conversation = Conversation::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($conversation);

            $this->actingAs($user)->postJson('/cabinet/chats/'.$conversation->id.'/messages', [
                'body' => 'From Web',
                'client_message_id' => (string) Str::uuid(),
            ])->assertOk();

            $bodies = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', MessageRole::User)
                ->orderBy('id')
                ->pluck('body')
                ->all();

            $this->assertSame(['From Telegram', 'From Web'], $bodies);
            $channels = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', MessageRole::User)
                ->orderBy('id')
                ->pluck('channel')
                ->map(fn ($channel) => $channel instanceof MessageChannel ? $channel->value : $channel)
                ->all();
            $this->assertSame(['telegram', 'web'], $channels);

            $shared = $this->actingAs($user)->getJson('/cabinet/chats/'.$conversation->id.'/messages')->assertOk();
            $this->assertStringContainsString('From Telegram', $shared->getContent());
            $this->assertStringContainsString('From Web', $shared->getContent());

            $conversationCalls = $fake->conversationCalls();
            $this->assertGreaterThanOrEqual(2, count($conversationCalls));
            $lastPromptMessages = array_map(
                static fn ($message) => $message->content,
                $conversationCalls[count($conversationCalls) - 1]['request']->messages,
            );
            $this->assertContains('From Telegram', $lastPromptMessages);
            $this->assertContains('From Web', $lastPromptMessages);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_user_cannot_access_admin_routes_from_cabinet_context(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get('/settings')->assertForbidden();
            $this->actingAs($user)->get('/settings?tab=ai')->assertForbidden();
            $this->actingAs($user)->get('/dashboard')->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
