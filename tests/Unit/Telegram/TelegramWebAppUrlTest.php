<?php

namespace Tests\Unit\Telegram;

use App\Models\TelegramBotSetting;
use App\Services\Telegram\TelegramBotManager;
use App\Services\Telegram\WebApp\TelegramWebAppUrl;
use Tests\TestCase;

class TelegramWebAppUrlTest extends TestCase
{
    public function test_https_entry_stays_on_the_lavr_origin_and_encodes_start_param(): void
    {
        config([
            'app.url' => 'https://lavr.youngfashionshow.com',
            'telegram.webapp.path' => '/telegram/webapp',
        ]);

        $urls = new TelegramWebAppUrl($this->createMock(TelegramBotManager::class));

        $this->assertSame(
            'https://lavr.youngfashionshow.com/telegram/webapp',
            $urls->httpsEntry(),
        );
        $this->assertSame(
            'https://lavr.youngfashionshow.com/telegram/webapp?startapp=today',
            $urls->httpsEntry('today'),
        );
        $this->assertSame(
            'https://lavr.youngfashionshow.com/telegram/webapp?startapp=chat_12',
            $urls->httpsEntry('chat_12'),
        );
    }

    public function test_telegram_app_link_requires_bot_username(): void
    {
        $empty = $this->createMock(TelegramBotManager::class);
        $empty->method('existingSetting')->willReturn(null);

        $this->assertNull((new TelegramWebAppUrl($empty))->telegramAppLink('today'));

        $setting = new TelegramBotSetting;
        $setting->bot_username = 'lavr_test_bot';
        $named = $this->createMock(TelegramBotManager::class);
        $named->method('existingSetting')->willReturn($setting);

        $this->assertSame(
            'https://t.me/lavr_test_bot/app?startapp=reports',
            (new TelegramWebAppUrl($named))->telegramAppLink('reports'),
        );
    }
}
