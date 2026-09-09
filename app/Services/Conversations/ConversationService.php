<?php

namespace App\Services\Conversations;

use App\Enums\ConversationKind;
use App\Enums\ConversationStatus;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\JarvisNotification;
use App\Models\MemorySource;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\ChatAttachments\ChatAttachmentService;
use App\Services\Knowledge\KnowledgeDeletionService;
use App\Support\WorkspaceUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ConversationService
{
    public const LIST_LIMIT = 20;

    public const CABINET_LIST_LIMIT = 50;

    public const TITLE_MAX_LENGTH = 120;

    public const NEW_CHAT_TITLE = 'Новый чат';

    public function __construct(
        private readonly ChatAttachmentService $attachments,
        private readonly KnowledgeDeletionService $knowledge = new KnowledgeDeletionService,
    ) {}

    public function createPersonal(User $user, string $title): Conversation
    {
        $normalizedTitle = $this->normalizeTitle($title);

        return Conversation::query()->create([
            'user_id' => $user->id,
            'kind' => ConversationKind::Personal,
            'title' => $normalizedTitle,
            'status' => ConversationStatus::Active,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function listForUser(User $user, int $limit = self::LIST_LIMIT): Collection
    {
        return Conversation::query()
            ->where('user_id', $user->id)
            ->where('kind', ConversationKind::Personal)
            ->orderByRaw('last_activity_at IS NULL')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function findOwned(User $user, int $conversationId): ?Conversation
    {
        return Conversation::query()
            ->where('user_id', $user->id)
            ->where('kind', ConversationKind::Personal)
            ->whereKey($conversationId)
            ->first();
    }

    public function getOrCreateDefault(User $user): Conversation
    {
        $existing = Conversation::query()
            ->where('user_id', $user->id)
            ->where('kind', ConversationKind::Personal)
            ->where('title', Conversation::DEFAULT_TITLE)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createPersonal($user, Conversation::DEFAULT_TITLE);
    }

    public function latestOrDefault(User $user): Conversation
    {
        $latest = Conversation::query()
            ->where('user_id', $user->id)
            ->where('kind', ConversationKind::Personal)
            ->orderByRaw('last_activity_at IS NULL')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('updated_at')
            ->first();

        return $latest ?? $this->getOrCreateDefault($user);
    }

    public function rename(User $user, Conversation $conversation, string $title): Conversation
    {
        $owned = $this->findOwned($user, (int) $conversation->id);

        if ($owned === null) {
            throw new InvalidArgumentException('Conversation is not owned by this user.');
        }

        $owned->forceFill([
            'title' => $this->normalizeTitle($title),
        ])->save();

        return $owned->fresh();
    }

    /**
     * Hard-delete an owned personal conversation and its child chat data.
     * Independent entities (tasks, reminders, memories, stored files, projects) survive.
     *
     * @return array{success: true, deleted_id: int, conversation: array{id: int, title: string, last_activity_at: string|null}}
     */
    public function deletePersonal(User $user, Conversation $conversation): array
    {
        $owned = $this->findOwned($user, (int) $conversation->id);

        if ($owned === null) {
            abort(404);
        }

        $deletedId = (int) $owned->id;
        $diskCopies = MessageAttachment::query()
            ->whereIn('message_id', Message::query()->where('conversation_id', $deletedId)->select('id'))
            ->get();

        DB::transaction(function () use ($user, $owned, $deletedId): void {
            $this->detachIndependentSources($user, $deletedId);
            $this->rewireNotificationLinks($user, $deletedId);
            $owned->projects()->detach();
            $owned->delete();
        });

        $this->attachments->deleteDiskCopies($diskCopies);

        $next = $this->latestOrDefault($user);

        return [
            'success' => true,
            'deleted_id' => $deletedId,
            'conversation' => [
                'id' => (int) $next->id,
                'title' => $next->title,
                'last_activity_at' => optional($next->last_activity_at)?->toIso8601String(),
            ],
        ];
    }

    public function ensureOwned(User $user, int $conversationId): Conversation
    {
        $conversation = $this->findOwned($user, $conversationId);

        if ($conversation === null) {
            abort(404);
        }

        return $conversation;
    }

    public function ensureActiveConversation(ChannelIdentity $identity): Conversation
    {
        $identity->loadMissing('user');

        if ($identity->active_conversation_id !== null) {
            $active = $this->findOwned($identity->user, (int) $identity->active_conversation_id);

            if ($active !== null) {
                return $active;
            }
        }

        $conversation = $this->getOrCreateDefault($identity->user);
        $this->setActiveConversation($identity, $conversation);

        return $conversation;
    }

    public function setActiveConversation(ChannelIdentity $identity, Conversation $conversation): bool
    {
        if ((int) $identity->user_id !== (int) $conversation->user_id) {
            return false;
        }

        if ($conversation->kind !== ConversationKind::Personal) {
            return false;
        }

        if ($identity->active_conversation_id !== $conversation->id) {
            $identity->forceFill([
                'active_conversation_id' => $conversation->id,
            ])->save();
        }

        $identity->setRelation('activeConversation', $conversation);

        return true;
    }

    public function normalizeTitle(string $title): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $title) ?? '');

        if ($normalized === '') {
            throw new InvalidArgumentException('Conversation title is empty.');
        }

        if (mb_strlen($normalized) > self::TITLE_MAX_LENGTH) {
            throw new InvalidArgumentException('Conversation title is too long.');
        }

        return $normalized;
    }

    public function isValidTitle(string $title): bool
    {
        try {
            $this->normalizeTitle($title);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function detachIndependentSources(User $user, int $conversationId): void
    {
        Task::query()
            ->where('user_id', $user->id)
            ->where('source_conversation_id', $conversationId)
            ->update([
                'source_conversation_id' => null,
                'source_message_id' => null,
            ]);

        Reminder::query()
            ->where('user_id', $user->id)
            ->where('source_conversation_id', $conversationId)
            ->update([
                'source_conversation_id' => null,
                'source_message_id' => null,
            ]);

        MemorySource::query()
            ->where('conversation_id', $conversationId)
            ->update([
                'conversation_id' => null,
                'message_id' => null,
                'summary_id' => null,
            ]);

        $this->knowledge->detachConversation($user, $conversationId);
    }

    private function rewireNotificationLinks(User $user, int $conversationId): void
    {
        $root = WorkspaceUrl::PREFIX;
        $prefixes = [
            '/lavr/chats/'.$conversationId,
            '/jarvis/chats/'.$conversationId,
            '/chat/chats/'.$conversationId,
        ];

        $notifications = JarvisNotification::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $index => $prefix) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($inner) use ($prefix): void {
                        $inner->where('action_url', $prefix)
                            ->orWhere('action_url', 'like', $prefix.'?%');
                    });
                }
            })
            ->get();

        foreach ($notifications as $notification) {
            $actionUrl = (string) ($notification->action_url ?? '');
            $nextUrl = $root;

            foreach ($prefixes as $prefix) {
                if ($actionUrl === $prefix) {
                    $nextUrl = $root;
                    break;
                }

                if (str_starts_with($actionUrl, $prefix.'?')) {
                    $nextUrl = $root.'?'.substr($actionUrl, strlen($prefix) + 1);
                    break;
                }
            }

            $notification->forceFill([
                'action_url' => $nextUrl,
            ])->save();
        }
    }
}
