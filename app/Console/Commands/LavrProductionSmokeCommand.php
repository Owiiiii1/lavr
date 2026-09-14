<?php

namespace App\Console\Commands;

use App\Services\Readiness\ProductionSmokeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lavr:production-smoke')]
#[Description('Read-only production smoke checks')]
class LavrProductionSmokeCommand extends Command
{
    public function handle(ProductionSmokeService $smoke): int
    {
        $result = $smoke->run();
        $this->info('production-smoke '.$result['overall']);

        foreach (['database', 'owner', 'registration', 'app_debug', 'storage', 'scheduler', 'queue', 'ai'] as $key) {
            $check = $result['checks'][$key] ?? null;
            if (! is_array($check)) {
                continue;
            }
            $this->line(strtoupper((string) $check['acceptance']).'  '.$key.' — '.$check['message']);
        }

        return $result['exit_fail'] ? self::FAILURE : self::SUCCESS;
    }
}
