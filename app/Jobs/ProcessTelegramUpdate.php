<?php

namespace App\Jobs;

use App\Models\TelegramBotSetting;
use App\Services\Telegram\TelegramWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * One Telegram STT attempt (default 90s) plus download and the conversation turn.
     * Stays under the queue worker --timeout=180.
     */
    public int $timeout = 170;

    public function __construct(
        public readonly string $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(TelegramWebhookProcessor $processor): void
    {
        $setting = TelegramBotSetting::query()->first();

        if ($setting === null || ! filled($setting->bot_token)) {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        $processor->process($this->payload, (string) $setting->bot_token);
    }
}
