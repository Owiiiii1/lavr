<?php

namespace App\Services\Automation;

use App\Enums\JarvisNotificationType;
use App\Models\ChannelIdentity;
use App\Models\User;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use Throwable;

final class AutomationDelivery
{
    public function __construct(
        private readonly JarvisNotificationService $inbox = new JarvisNotificationService,
        private readonly ?SendsReminderTelegram $telegram = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{status: string, reference: ?string}
     */
    public function inApp(
        User $user,
        JarvisNotificationType $type,
        string $title,
        string $body,
        string $dedupeKey,
        string $sourceType,
        int $sourceId,
        string $actionUrl,
        array $metadata = [],
        bool $aiPhrased = false,
    ): array {
        $row = $this->inbox->record(
            $user,
            $type,
            $title,
            $body,
            $dedupeKey,
            $sourceType,
            $sourceId,
            $actionUrl,
            $metadata,
            $aiPhrased,
        );

        return [
            'status' => $row === null ? 'deduped' : 'success',
            'reference' => $row !== null ? (string) $row->id : null,
        ];
    }

    /**
     * @return array{status: string, reference: ?string}
     */
    public function telegramOwner(User $user, string $text, ?string $startParam, string $deliveryKey): array
    {
        if ($this->telegram === null || ! $user->isActive()) {
            return ['status' => 'skipped', 'reference' => null];
        }

        $identity = ChannelIdentity::findTelegramForUser((int) $user->id);
        $chatId = ($identity !== null && filled($identity->external_chat_id))
            ? (string) $identity->external_chat_id
            : null;

        if ($chatId === null) {
            return ['status' => 'skipped', 'reference' => null];
        }

        try {
            $this->telegram->send($chatId, $text, $startParam);

            return ['status' => 'success', 'reference' => $deliveryKey];
        } catch (Throwable) {
            return ['status' => 'failed', 'reference' => null];
        }
    }
}
