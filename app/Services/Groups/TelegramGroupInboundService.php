<?php

namespace App\Services\Groups;

use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\TelegramGroupStatus;
use App\Models\Message;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupParticipant;
use App\Services\Conversations\MessagePersistenceService;
use App\Services\Conversations\PersistMessageData;
use App\Services\Sources\SourceIngestService;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Telegram\Types\Message\Message as TelegramMessage;
use Throwable;

final class TelegramGroupInboundService
{
    public function __construct(
        private readonly TelegramGroupDiscoveryService $discovery,
        private readonly TelegramGroupMessageMapper $mapper,
        private readonly MessagePersistenceService $messages,
        private readonly SourceIngestService $ingest,
    ) {}

    public function handleMessage(TelegramMessage $message, bool $edited = false): void
    {
        $started = microtime(true);
        $chat = $message->chat;

        if ($chat === null || $chat->id === null) {
            return;
        }

        $telegramChatId = (string) $chat->id;
        $chatType = is_string($chat->type) ? $chat->type : $chat->type->value;
        $group = $this->discovery->discoverOrCreate($telegramChatId, [
            'title' => $chat->title ?? null,
            'username' => $chat->username ?? null,
            'chat_type' => $chatType,
            'is_forum' => (bool) ($chat->is_forum ?? false),
        ]);
        $group->loadMissing('conversation');

        if ($group->status === TelegramGroupStatus::Left) {
            $group->forceFill([
                'status' => TelegramGroupStatus::Connected,
                'last_seen_at' => now(),
            ])->save();
        }

        $mapped = $this->mapper->map($message);
        $outcome = $edited
            ? $this->applyEdit($group, $mapped)
            : $this->persistNew($group, $mapped);

        if ($mapped['participant'] !== null) {
            $this->upsertParticipant($group, $mapped['participant']);
        }

        Log::info('telegram group inbound', [
            'telegram_group_id' => $group->id,
            'update_type' => $edited ? 'edited_message' : 'message',
            'message_type' => $mapped['message_type']->value,
            'outcome' => $outcome,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function persistNew(TelegramGroup $group, array $mapped): string
    {
        $result = $this->messages->persistRaw(new PersistMessageData(
            conversation: $group->conversation,
            role: $mapped['role'],
            channel: MessageChannel::Telegram,
            messageType: $mapped['message_type'],
            body: $mapped['body'],
            channelMessageId: $mapped['channel_message_id'],
            occurredAt: $mapped['occurred_at'],
            metadata: $this->withGroupFlags($mapped['metadata'], $mapped['role']),
            telegramGroupId: $group->id,
            senderExternalId: $mapped['sender_external_id'],
            senderUsername: $mapped['sender_username'],
            senderName: $mapped['sender_name'],
            replyToChannelMessageId: $mapped['reply_to_channel_message_id'],
            threadId: $mapped['thread_id'],
            editedAt: $mapped['edited_at'],
        ));

        if ($result->created) {
            $this->touchCounters($group, $result->message);
            $this->maybeLinkOutboundRole($result->message, $mapped['role']);
            $this->ingestSource($group, $result->message, $mapped['role']);

            return 'persisted';
        }

        if ($mapped['edited_at'] !== null) {
            $this->applyEdit($group, $mapped);

            return 'updated';
        }

        return 'duplicate';
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function applyEdit(TelegramGroup $group, array $mapped): string
    {
        $existing = $this->messages->findByChannelMessage(
            MessageChannel::Telegram->value,
            (int) $group->conversation_id,
            $mapped['channel_message_id'],
        );

        if ($existing === null) {
            return $this->persistNew($group, $mapped);
        }

        $metadata = $existing->metadata ?? [];
        $incoming = $mapped['metadata'] ?? [];
        if (isset($incoming['telegram']) && is_array($incoming['telegram'])) {
            $metadata['telegram'] = array_merge($metadata['telegram'] ?? [], $incoming['telegram']);
        }

        $existing->forceFill([
            'body' => $mapped['body'],
            'metadata' => $metadata,
            'edited_at' => $mapped['edited_at'] ?? now(),
            'sender_external_id' => $mapped['sender_external_id'] ?? $existing->sender_external_id,
            'sender_username' => $mapped['sender_username'] ?? $existing->sender_username,
            'sender_name' => $mapped['sender_name'] ?? $existing->sender_name,
        ])->save();

        $group->forceFill(['last_seen_at' => now()])->save();

        return 'updated';
    }

    /**
     * @param  array{
     *     telegram_user_id: string,
     *     username: string|null,
     *     first_name: string|null,
     *     last_name: string|null,
     *     display_name: string|null,
     *     is_bot: bool
     * }  $participant
     */
    public function upsertParticipant(TelegramGroup $group, array $participant): void
    {
        $row = TelegramGroupParticipant::query()->firstOrNew([
            'telegram_group_id' => $group->id,
            'telegram_user_id' => $participant['telegram_user_id'],
        ]);

        $now = now();

        $row->forceFill([
            'username' => $participant['username'],
            'first_name' => $participant['first_name'],
            'last_name' => $participant['last_name'],
            'display_name' => $participant['display_name'],
            'is_bot' => $participant['is_bot'],
            'last_seen_at' => $now,
        ]);

        if (! $row->exists) {
            $row->first_seen_at = $now;
        }

        $row->save();
    }

    private function touchCounters(TelegramGroup $group, Message $message): void
    {
        $group->forceFill([
            'message_count' => (int) $group->message_count + 1,
            'last_message_at' => $message->occurred_at,
            'last_seen_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withGroupFlags(array $metadata, MessageRole $role): array
    {
        $metadata['group'] = true;

        if ($role === MessageRole::Assistant) {
            $metadata['group_bot'] = true;
        }

        return $metadata;
    }

    private function ingestSource(TelegramGroup $group, Message $message, MessageRole $role): void
    {
        $user = $group->conversation?->user;
        if ($user === null) {
            return;
        }

        try {
            $this->ingest->ingestTelegramMessage(
                $user,
                $group,
                $message,
                $role === MessageRole::User && $message->sender_external_id === null,
            );
        } catch (Throwable) {
        }
    }

    private function maybeLinkOutboundRole(Message $message, MessageRole $role): void
    {
        if ($role !== MessageRole::Assistant) {
            return;
        }

        $metadata = $message->metadata ?? [];
        if (($metadata['group_outbound'] ?? false) === true) {
            return;
        }

        $message->forceFill([
            'role' => MessageRole::Assistant,
        ])->save();
    }
}
