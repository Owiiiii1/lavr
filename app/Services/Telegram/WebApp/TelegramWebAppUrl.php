<?php

namespace App\Services\Telegram\WebApp;

use App\Services\Telegram\TelegramBotManager;

final class TelegramWebAppUrl
{
    public function __construct(
        private readonly TelegramBotManager $bots,
    ) {}

    /**
     * HTTPS entry used by BotFather / Menu Button (no secrets).
     */
    public function httpsEntry(?string $startParam = null): string
    {
        $url = rtrim((string) config('app.url'), '/').(string) config('telegram.webapp.path', '/telegram/webapp');

        if ($startParam !== null && $startParam !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').'startapp='.rawurlencode($startParam);
        }

        return $url;
    }

    /**
     * t.me Mini App link for an inline "Open in LAVR" button.
     */
    public function telegramAppLink(?string $startParam = null): ?string
    {
        $username = ltrim((string) ($this->bots->existingSetting()?->bot_username ?? ''), '@');
        $shortName = trim((string) config('telegram.webapp.short_name', 'app'));

        if ($username === '' || $shortName === '') {
            return null;
        }

        $url = 'https://t.me/'.$username.'/'.$shortName;

        if ($startParam !== null && $startParam !== '') {
            $url .= '?startapp='.rawurlencode($startParam);
        }

        return $url;
    }
}
