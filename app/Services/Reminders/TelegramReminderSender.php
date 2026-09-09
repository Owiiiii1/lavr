<?php

namespace App\Services\Reminders;

use App\Services\Reminders\Contracts\SendsReminderTelegram;
use App\Services\Telegram\TelegramBotManager;
use App\Services\Telegram\WebApp\TelegramWebAppOpenButton;
use App\Services\Telegram\WebApp\TelegramWebAppUrl;

final class TelegramReminderSender implements SendsReminderTelegram
{
    public function __construct(
        private readonly TelegramBotManager $telegram,
        private readonly TelegramWebAppUrl $urls,
    ) {}

    public function send(string $chatId, string $text, ?string $webAppStartParam = null): void
    {
        $markup = null;

        if (is_string($webAppStartParam) && $webAppStartParam !== '') {
            $markup = TelegramWebAppOpenButton::replyMarkup(
                $this->urls->httpsEntry($webAppStartParam),
            );
        }

        $this->telegram->sendTextMessage($chatId, $text, $markup);
    }
}
