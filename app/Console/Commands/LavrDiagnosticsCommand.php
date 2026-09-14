<?php

namespace App\Console\Commands;

use App\Services\Readiness\LavrDiagnosticsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lavr:diagnostics {--json}')]
#[Description('Safe LAVR diagnostics with no secrets')]
class LavrDiagnosticsCommand extends Command
{
    public function handle(LavrDiagnosticsService $diagnostics): int
    {
        $snapshot = $diagnostics->snapshot();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('LAVR diagnostics · '.$snapshot['overall']);
        foreach ($snapshot['checklist'] as $item) {
            $this->line(strtoupper((string) $item['state']).'  '.$item['label'].' — '.$item['detail']);
        }

        return self::SUCCESS;
    }
}
