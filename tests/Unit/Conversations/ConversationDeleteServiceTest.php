<?php

namespace Tests\Unit\Conversations;

use App\Enums\AttachmentRetentionClass;
use App\Enums\ConversationKind;
use App\Enums\JarvisNotificationSeverity;
use App\Enums\JarvisNotificationType;
use App\Enums\MemoryKind;
use App\Enums\MemoryScope;
use App\Enums\MemorySourceKind;
use App\Enums\MemoryStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\ProjectStatus;
use App\Enums\ReminderStatus;
use App\Enums\StoredFileStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Conversation;
use App\Models\JarvisNotification;
use App\Models\Memory;
use App\Models\MemorySource;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageStoredFile;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\StoredFile;
use App\Models\Task;
use App\Services\ChatAttachments\ChatAttachmentConfig;
use App\Services\Conversations\ConversationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ConversationDeleteServiceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_deleting_chat_nulls_task_source_and_keeps_the_task(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->personalChat($user, 'Task source');
            $message = $this->addMessage($conversation, 'сделай отчёт');
            $task = Task::query()->create([
                'user_id' => $user->id,
                'title' => 'Отчёт',
                'status' => TaskStatus::Open,
                'priority' => TaskPriority::Normal,
                'source_conversation_id' => $conversation->id,
                'source_message_id' => $message->id,
            ]);

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $task->refresh();
            $this->assertNull($task->source_conversation_id);
            $this->assertNull($task->source_message_id);
            $this->assertSame('Отчёт', $task->title);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_deleting_chat_nulls_reminder_source_and_keeps_the_reminder(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->personalChat($user, 'Reminder source');
            $message = $this->addMessage($conversation, 'напомни');
            $reminder = Reminder::query()->create([
                'user_id' => $user->id,
                'source_conversation_id' => $conversation->id,
                'source_message_id' => $message->id,
                'text' => 'Позвонить',
                'run_at' => now()->addDay(),
                'timezone' => 'Europe/Rome',
                'status' => ReminderStatus::Scheduled,
            ]);

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $reminder->refresh();
            $this->assertNull($reminder->source_conversation_id);
            $this->assertNull($reminder->source_message_id);
            $this->assertSame('Позвонить', $reminder->text);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_project_survives_and_conversation_pivot_is_detached(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->personalChat($user, 'Attached');
            $project = Project::query()->create([
                'user_id' => $user->id,
                'name' => 'Keep project',
                'normalized_name' => 'keep-project-'.Str::lower(Str::random(8)),
                'status' => ProjectStatus::Active,
            ]);
            $project->conversations()->attach($conversation->id, [
                'attached_at' => now(),
            ]);

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $this->assertDatabaseHas('projects', ['id' => $project->id]);
            $this->assertSame(0, $project->conversations()->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_persistent_stored_file_survives_and_message_link_is_removed(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->personalChat($user, 'Files');
            $message = $this->addMessage($conversation, 'файл');
            $file = StoredFile::query()->create([
                'user_id' => $user->id,
                'public_id' => (string) Str::uuid(),
                'original_name' => 'notes.txt',
                'display_name' => 'notes.txt',
                'normalized_name' => 'notes.txt',
                'mime_type' => 'text/plain',
                'extension' => 'txt',
                'size_bytes' => 12,
                'storage_disk' => 'local',
                'storage_path' => 'stored/test-notes.txt',
                'status' => StoredFileStatus::Ready,
                'uploaded_at' => now(),
            ]);
            MessageStoredFile::query()->create([
                'message_id' => $message->id,
                'stored_file_id' => $file->id,
                'attached_at' => now(),
            ]);

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $this->assertDatabaseHas('stored_files', ['id' => $file->id, 'display_name' => 'notes.txt']);
            $this->assertDatabaseMissing('message_stored_files', ['stored_file_id' => $file->id]);
            $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_ephemeral_attachment_bytes_are_removed_from_disk(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $disk = ChatAttachmentConfig::disk();
            Storage::fake($disk);
            $path = 'chat-attachments/'.$user->id.'/shot.jpg';
            $thumb = 'chat-attachments/'.$user->id.'/shot_thumb.jpg';
            Storage::disk($disk)->put($path, 'image-bytes');
            Storage::disk($disk)->put($thumb, 'thumb-bytes');

            $conversation = $this->personalChat($user, 'Screenshots');
            $message = $this->addMessage($conversation, 'скрин');
            MessageAttachment::query()->create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'kind' => MessageAttachment::KIND_IMAGE,
                'retention_class' => AttachmentRetentionClass::Ephemeral,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 11,
                'metadata' => ['thumbnail_path' => $thumb],
            ]);

            app(ConversationService::class)->deletePersonal($user, $conversation);

            Storage::disk($disk)->assertMissing($path);
            Storage::disk($disk)->assertMissing($thumb);
            $this->assertDatabaseMissing('message_attachments', ['message_id' => $message->id]);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_durable_memory_survives_with_source_detached(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->personalChat($user, 'Memory source');
            $message = $this->addMessage($conversation, 'я учу python');
            $memory = Memory::query()->create([
                'user_id' => $user->id,
                'scope' => MemoryScope::Personal,
                'kind' => MemoryKind::Fact,
                'content' => 'Учит Python',
                'normalized_key' => 'uchit python',
                'confidence' => 0.9,
                'status' => MemoryStatus::Active,
                'first_seen_at' => now(),
                'last_confirmed_at' => now(),
            ]);
            $source = MemorySource::query()->create([
                'memory_id' => $memory->id,
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'source_kind' => MemorySourceKind::DirectConversation,
            ]);

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $this->assertDatabaseHas('memories', ['id' => $memory->id, 'content' => 'Учит Python']);
            $source->refresh();
            $this->assertNull($source->conversation_id);
            $this->assertNull($source->message_id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_notification_chat_link_falls_back_to_workspace_root(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->personalChat($user, 'Linked');
            $notification = JarvisNotification::query()->create([
                'user_id' => $user->id,
                'type' => JarvisNotificationType::TaskDue,
                'title' => 'Задача',
                'body' => 'Срок',
                'severity' => JarvisNotificationSeverity::Info,
                'dedupe_key' => 'delete-chat-'.Str::lower(Str::random(8)),
                'action_url' => '/lavr/chats/'.$conversation->id.'?task=9',
                'occurred_at' => now(),
            ]);

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $notification->refresh();
            $this->assertSame('/lavr?task=9', $notification->action_url);
            $this->assertDatabaseHas('jarvis_notifications', ['id' => $notification->id]);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_no_orphan_messages_remain_after_delete(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->personalChat($user, 'Orphans');
            $this->addMessage($conversation, 'one');
            $this->addMessage($conversation, 'two');
            $id = $conversation->id;

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $this->assertSame(0, Message::query()->where('conversation_id', $id)->count());
            $this->assertDatabaseMissing('conversations', ['id' => $id]);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_transaction_rolls_back_when_delete_fails_midway(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $keep = $this->personalChat($user, 'Keep');
            $remove = $this->personalChat($user, 'Boom');
            $message = $this->addMessage($remove, 'keep me');

            Event::listen('eloquent.deleted: '.Conversation::class, function (Conversation $deleted) use ($remove): void {
                if ((int) $deleted->id === (int) $remove->id) {
                    throw new RuntimeException('forced delete failure');
                }
            });

            try {
                app(ConversationService::class)->deletePersonal($user, $remove);
                $this->fail('Delete should have aborted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced delete failure', $exception->getMessage());
            }

            $this->assertDatabaseHas('conversations', ['id' => $remove->id, 'title' => 'Boom']);
            $this->assertDatabaseHas('messages', ['id' => $message->id]);
            $this->assertDatabaseHas('conversations', ['id' => $keep->id]);
        } finally {
            Event::forget('eloquent.deleted: '.Conversation::class);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_deleting_the_last_chat_opens_a_fresh_default_conversation(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $only = $this->personalChat($user, 'Only');

            $payload = app(ConversationService::class)->deletePersonal($user, $only);

            $this->assertNotSame($only->id, $payload['conversation']['id']);
            $this->assertSame(Conversation::DEFAULT_TITLE, $payload['conversation']['title']);
            $this->assertDatabaseMissing('conversations', ['id' => $only->id]);
            $this->assertSame(1, Conversation::query()->where('user_id', $user->id)->where('kind', ConversationKind::Personal)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function personalChat($user, string $title): Conversation
    {
        return app(ConversationService::class)->createPersonal($user, $title);
    }

    private function addMessage(Conversation $conversation, string $body): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'role' => MessageRole::User,
            'channel' => MessageChannel::Web,
            'body' => $body,
            'message_type' => MessageType::Text,
            'occurred_at' => now(),
        ]);
    }
}
