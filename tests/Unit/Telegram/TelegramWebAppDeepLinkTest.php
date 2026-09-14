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
        $this->assertSame('/lavr/meetings/8', $links->resolve('meeting_8', null));
        $this->assertTrue($links->isAllowlisted('/lavr/meetings/8'));
        $this->assertSame('/lavr/notifications', $links->resolve('notifications', null));
        $this->assertSame('/lavr/reports', $links->resolve('reports', null));
        $this->assertSame('/lavr', $links->resolve('chat', null));
        $this->assertSame('/lavr/commitments/3', $links->resolve('commitment_3', null));
        $this->assertSame('/lavr/briefs/9', $links->resolve('brief_9', null));
        $this->assertSame('/lavr/briefs', $links->resolve('brief', null));
        $this->assertSame('/lavr/leadership/4', $links->resolve('leadership_4', null));
        $this->assertTrue($links->isAllowlisted('/lavr/leadership/4'));
        $this->assertTrue($links->isAllowlisted('/lavr/briefs/9'));
        $this->assertSame('/lavr/today', $links->resolve(null, 'https://evil.example/phish'));
        $this->assertSame('/lavr/today', $links->resolve(null, '//evil.example'));
        $this->assertSame('/lavr/today', $links->resolve(null, '/dashboard'));
        $this->assertTrue($links->isAllowlisted('/lavr/people'));
        $this->assertFalse($links->isAllowlisted('/settings'));
    }
}
