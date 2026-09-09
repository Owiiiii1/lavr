<?php

namespace Tests\Unit\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use Tests\TestCase;

class ProcessTelegramUpdateQueueTest extends TestCase
{
    public function test_telegram_updates_are_dispatched_on_the_telegram_queue(): void
    {
        $job = new ProcessTelegramUpdate('{}');

        $this->assertSame('default', $job->queue);
    }
}
