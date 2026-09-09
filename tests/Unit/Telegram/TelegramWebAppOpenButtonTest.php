<?php

namespace Tests\Unit\Telegram;

use App\Services\Telegram\WebApp\TelegramWebAppOpenButton;
use Tests\TestCase;

class TelegramWebAppOpenButtonTest extends TestCase
{
    public function test_builds_allowlisted_web_app_markup(): void
    {
        $url = 'https://lavr.youngfashionshow.com/telegram/webapp?startapp=today';
        $markup = TelegramWebAppOpenButton::replyMarkup($url);

        $this->assertSame('Open in LAVR', $markup['inline_keyboard'][0][0]['text']);
        $this->assertSame($url, $markup['inline_keyboard'][0][0]['web_app']['url']);
        $this->assertArrayNotHasKey('url', $markup['inline_keyboard'][0][0]);
    }
}
