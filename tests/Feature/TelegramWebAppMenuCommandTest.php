<?php

namespace Tests\Feature;

use App\Models\TelegramBotSetting;
use App\Services\Telegram\TelegramBotManager;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebAppMenuCommandTest extends TestCase
{
    public function test_fails_without_token_and_does_not_call_telegram(): void
    {
        Http::fake();

        $this->mock(TelegramBotManager::class, function ($mock): void {
            $mock->shouldReceive('existingSetting')->andReturn(null);
        });

        $this->artisan('telegram:set-webapp-menu')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_sets_menu_button_without_changing_webhook(): void
    {
        config(['app.url' => 'https://lavr.youngfashionshow.com']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $setting = new TelegramBotSetting;
        $setting->bot_token = '123456:TEST-TOKEN';
        $setting->bot_username = 'lavr_test_bot';

        $this->mock(TelegramBotManager::class, function ($mock) use ($setting): void {
            $mock->shouldReceive('existingSetting')->andReturn($setting);
        });

        $this->artisan('telegram:set-webapp-menu')->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/setChatMenuButton')
                && ($data['menu_button']['text'] ?? null) === 'Open LAVR'
                && ($data['menu_button']['web_app']['url'] ?? null) === 'https://lavr.youngfashionshow.com/telegram/webapp';
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'setWebhook'));
    }
}
