<?php

namespace App\Jobs;

use App\Services\Readiness\HeartbeatRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function handle(HeartbeatRecorder $heartbeats): void
    {
        $heartbeats->recordQueue();
    }
}
