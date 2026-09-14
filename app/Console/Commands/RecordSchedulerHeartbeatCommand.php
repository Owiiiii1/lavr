<?php

namespace App\Console\Commands;

use App\Jobs\RecordQueueHeartbeatJob;
use App\Services\Readiness\HeartbeatRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lavr:heartbeat')]
#[Description('Record scheduler heartbeat and dispatch a queue worker ping')]
class RecordSchedulerHeartbeatCommand extends Command
{
    public function handle(HeartbeatRecorder $heartbeats): int
    {
        $heartbeats->recordScheduler();
        RecordQueueHeartbeatJob::dispatch();

        return self::SUCCESS;
    }
}
