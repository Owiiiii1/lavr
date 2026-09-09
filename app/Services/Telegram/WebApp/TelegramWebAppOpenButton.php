<?php

namespace App\Services\Telegram\WebApp;

final class TelegramWebAppOpenButton
{
    /**
     * Inline WebApp button for key Telegram notifications (not ordinary chat replies).
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    public static function replyMarkup(string $httpsEntry, string $text = 'Open in LAVR'): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => $text,
                    'web_app' => ['url' => $httpsEntry],
                ],
            ]],
        ];
    }
}
