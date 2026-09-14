<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OperationalControl\OperationalControlScanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('operational-control:scan {--user=} {--limit=40}')]
#[Description('Scan owners for operational events and proactive proposals')]
class ScanOperationalControlCommand extends Command
{
    public function handle(OperationalControlScanService $scan): int
    {
        $userId = (int) $this->option('user');
        if ($userId > 0) {
            $user = User::query()->find($userId);
            $count = $user instanceof User ? $scan->scanUser($user) : 0;
        } else {
            $count = $scan->scanAll((int) $this->option('limit'));
        }

        $this->info('Recorded '.$count.' operational proposal(s).');

        return self::SUCCESS;
    }
}
