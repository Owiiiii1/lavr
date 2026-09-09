<?php

namespace Tests\Feature;

use App\Enums\ConversationKind;
use App\Enums\ConversationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversations\ConversationService;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class WorkspaceConversationDeleteTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_is_redirected_from_chat_delete(): void
    {
        $this->delete(route('jarvis.chats.destroy', 1))->assertRedirect(route('login'));
        $this->delete(route('jarvis.chats.destroy', 1))->assertRedirect(route('login'));
    }

    public function test_user_can_delete_own_conversation_and_messages(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $service = app(ConversationService::class);
            $keep = $service->createPersonal($user, 'Keep');
            $remove = $service->createPersonal($user, 'Remove');
            $message = $this->addMessage($remove, $user->id, 'secret note');

            $response = $this->actingAs($user)->deleteJson(route('jarvis.chats.destroy', $remove->id));

            $response->assertOk();
            $response->assertJsonPath('success', true);
            $response->assertJsonPath('deleted_id', $remove->id);
            $response->assertJsonPath('conversation.id', $keep->id);
            $this->assertDatabaseMissing('conversations', ['id' => $remove->id]);
            $this->assertDatabaseMissing('messages', ['id' => $message->id]);
            $this->assertDatabaseHas('conversations', ['id' => $keep->id]);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_returns_404_when_deleting_foreign_conversation(): void
    {
        $owner = null;
        $stranger = null;

        try {
            $owner = $this->createTemporaryUser();
            $stranger = $this->createTemporaryUser();
            $foreign = app(ConversationService::class)->createPersonal($owner, 'Private');

            $this->actingAs($stranger)
                ->deleteJson(route('jarvis.chats.destroy', $foreign->id))
                ->assertNotFound();

            $this->assertDatabaseHas('conversations', ['id' => $foreign->id, 'user_id' => $owner->id]);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($stranger);
        }
    }

    public function test_owner_role_does_not_bypass_foreign_personal_chats(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->createTemporaryUser();
            $owner->forceFill(['role' => UserRole::Owner])->save();
            $user = $this->createTemporaryUser();
            $foreign = app(ConversationService::class)->createPersonal($user, 'User chat');

            $this->actingAs($owner)
                ->deleteJson(route('jarvis.chats.destroy', $foreign->id))
                ->assertNotFound();

            $this->assertDatabaseHas('conversations', ['id' => $foreign->id, 'user_id' => $user->id]);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_group_conversation_is_not_deleted_via_workspace(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $group = Conversation::query()->create([
                'user_id' => $user->id,
                'kind' => ConversationKind::Group,
                'title' => 'Group raw',
                'status' => ConversationStatus::Active,
                'last_activity_at' => now(),
            ]);

            $this->actingAs($user)
                ->deleteJson(route('jarvis.chats.destroy', $group->id))
                ->assertNotFound();

            $this->assertDatabaseHas('conversations', ['id' => $group->id, 'kind' => ConversationKind::Group->value]);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_deleting_the_open_chat_returns_another_existing_conversation(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $service = app(ConversationService::class);
            $older = $service->createPersonal($user, 'Older');
            $older->forceFill(['last_activity_at' => now()->subHour()])->save();
            $current = $service->createPersonal($user, 'Current');
            $current->forceFill(['last_activity_at' => now()])->save();

            $response = $this->actingAs($user)->deleteJson(route('jarvis.chats.destroy', $current->id));

            $response->assertOk();
            $response->assertJsonPath('conversation.id', $older->id);
            $this->assertDatabaseMissing('conversations', ['id' => $current->id]);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function addMessage(Conversation $conversation, int $userId, string $body): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
            'role' => MessageRole::User,
            'channel' => MessageChannel::Web,
            'body' => $body,
            'message_type' => MessageType::Text,
            'occurred_at' => now(),
        ]);
    }
}
