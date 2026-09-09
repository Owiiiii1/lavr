<?php

namespace Tests\Unit\Telegram;

use App\Services\Telegram\WebApp\TelegramWebAppDeepLink;
use Tests\TestCase;

class TelegramWebAppDeepLinkTest extends TestCase
{
    public function test_maps_known_start_params_and_rejects_external_urls(): void
    {
        $links = new TelegramWebAppDeepLink;

        $this->assertSame('/lavr/today', $links->resolve(null, null));
        $this->assertSame('/lavr/chats/12', $links->resolve('chat_12', null));
        $this->assertSame('/lavr/projects/4', $links->resolve('project_4', null));
        $this->assertSame('/lavr/people', $links->resolve('people', 'https://evil.example/phish'));
        $this->assertSame('/lavr/meetings', $links->resolve('meetings', null));
        $this->assertSame('/lavr/today', $links->resolve(null, 'https://evil.example/phish'));
        $this->assertSame('/lavr/today', $links->resolve(null, '//evil.example'));
        $this->assertSame('/lavr/today', $links->resolve(null, '/dashboard'));
        $this->assertTrue($links->isAllowlisted('/lavr/people'));
        $this->assertFalse($links->isAllowlisted('/settings'));
    }
}
