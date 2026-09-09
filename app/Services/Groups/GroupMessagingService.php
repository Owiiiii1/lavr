<?php

namespace App\Services\Groups;

use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\TelegramGroupStatus;
use App\Models\Message;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Conversations\MessagePersistenceService;
use App\Services\Conversations\PersistMessageData;
use App\Services\Groups\Exceptions\TelegramGroupException;
use App\Services\Telegram\Exceptions\TelegramSendException;
use App\Services\Telegram\TelegramBotManager;
use App\Services\Users\UserCapability;
use Illuminate\Support\Facades\Log;

final class GroupMessagingService
{
    public function __construct(
        private readonly TelegramBotManager $telegram,
        private readonly MessagePersistenceService $messages,
    ) {}

    public function send(User $actor, TelegramGroup $group, string $text): Message
    {
        $this->assertCanManage($actor);
        $group->loadMissing('conversation');

        $body = trim($text);

        if ($body === '') {
            throw new TelegramGroupException('empty_body', 'Message body is empty.');
        }

        if ($group->status === TelegramGroupStatus::Left) {
            throw new TelegramGroupException('group_left', 'The bot is no longer in this group.');
        }

        $started = microtime(true);

        try {
            $result = $this->telegram->sendTextMessage((string) $group->telegram_chat_id, $body);
        } catch (TelegramSendException $exception) {
            $this->applySendFailure($group, $exception);

            Log::warning('telegram group outbound failed', [
                'telegram_group_id' => $group->id,
                'error_class' => $exception->errorClass,
                'telegram_error_code' => $exception->telegramErrorCode,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            throw new TelegramGroupException(
                $exception->errorClass,
                $this->userMessage($exception),
                $exception->telegramErrorCode,
                $exception->errorClass,
            );
        }

        $messageId = (string) ($result['message_id'] ?? '');

        $persisted = $this->messages->persistOutbound(new PersistMessageData(
            conversation: $group->conversation,
            role: MessageRole::Assistant,
            channel: MessageChannel::Telegram,
            messageType: MessageType::Text,
            body: $body,
            channelMessageId: $messageId !== '' ? $messageId : null,
            occurredAt: now(),
            metadata: [
                'group' => true,
                'group_outbound' => true,
                'group_bot' => true,
            ],
            telegramGroupId: $group->id,
            senderName: 'LAVR',
        ));

        if ($persisted->created) {
            $group->forceFill([
                'message_count' => (int) $group->message_count + 1,
                'last_message_at' => $persisted->message->occurred_at,
                'last_seen_at' => now(),
            ])->save();
        }

        Log::info('telegram group outbound', [
            'telegram_group_id' => $group->id,
            'update_type' => 'admin_outbound',
            'outcome' => $persisted->created ? 'persisted' : 'duplicate',
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $persisted->message;
    }

    public function updateTimezone(User $actor, TelegramGroup $group, ?string $timezone): TelegramGroup
    {
        $this->assertCanManage($actor);

        $timezone = $timezone !== null ? trim($timezone) : null;

        if ($timezone === '') {
            $timezone = null;
        }

        if ($timezone !== null && ! TelegramGroup::isValidTimezone($timezone)) {
            throw new TelegramGroupException('invalid_timezone', 'Timezone must be a valid IANA identifier.');
        }

        $group->forceFill(['timezone' => $timezone])->save();

        return $group->fresh();
    }

    public function assertCanManage(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->canUseCapability(UserCapability::TELEGRAM_GROUPS)) {
            throw new TelegramGroupException('forbidden', 'Not allowed to manage Telegram groups.');
        }
    }

    private function applySendFailure(TelegramGroup $group, TelegramSendException $exception): void
    {
        $status = match ($exception->errorClass) {
            'kicked', 'not_found', 'forbidden' => TelegramGroupStatus::Left,
            'rights' => TelegramGroupStatus::Restricted,
            default => null,
        };

        if ($status === null) {
            return;
        }

        $group->forceFill([
            'status' => $status,
            'last_seen_at' => now(),
        ])->save();
    }

    private function userMessage(TelegramSendException $exception): string
    {
        return match ($exception->errorClass) {
            'kicked' => 'The bot was removed from this group.',
            'not_found' => 'Telegram could not find this chat.',
            'forbidden' => 'Telegram forbade sending to this group.',
            'rights' => 'The bot does not have permission to send messages in this group.',
            default => 'Failed to send the message to Telegram.',
        };
    }
}
