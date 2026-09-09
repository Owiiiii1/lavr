<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotManager;
use App\Services\Telegram\WebApp\TelegramWebAppUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetTelegramWebAppMenuCommand extends Command
{
    protected $signature = 'telegram:set-webapp-menu';

    protected $description = 'Set the Telegram DM Menu Button to Open LAVR Mini App (does not change webhook)';

    public function handle(TelegramBotManager $bots, TelegramWebAppUrl $urls): int
    {
        $setting = $bots->existingSetting();
        $token = trim((string) ($setting?->bot_token ?? ''));

        if ($token === '') {
            $this->error('Telegram bot token is not configured.');

            return self::FAILURE;
        }

        $entry = $urls->httpsEntry();

        $response = Http::timeout(15)
            ->asJson()
            ->post(
                'https://api.telegram.org/bot'.$token.'/setChatMenuButton',
                [
                    'menu_button' => [
                        'type' => 'web_app',
                        'text' => (string) config('telegram.webapp.menu_button_text', 'Open LAVR'),
                        'web_app' => [
                            'url' => $entry,
                        ],
                    ],
                ],
            );

        if (! $response->successful() || $response->json('ok') !== true) {
            $this->error('setChatMenuButton failed.');

            return self::FAILURE;
        }

        $this->info('Menu button set to Open LAVR → '.$entry);
        $this->comment('Webhook was not changed.');

        return self::SUCCESS;
    }
}
