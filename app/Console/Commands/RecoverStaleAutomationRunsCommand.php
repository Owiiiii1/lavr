<?php

namespace App\Console\Commands;

use App\Services\Automation\StaleAutomationRunRecovery;
use Illuminate\Console\Command;

class RecoverStaleAutomationRunsCommand extends Command
{
    protected $signature = 'automation:recover-stale-runs
        {--dry-run : Report matching rows without updating them}
        {--minutes= : Processing age threshold in minutes}';

    protected $description = 'Mark stuck automation_runs processing rows as retryable after a safe age threshold.';

    public function handle(StaleAutomationRunRecovery $recovery): int
    {
        $minutes = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) config('automation.stale_processing_minutes', 30);
        $execute = ! (bool) $this->option('dry-run');
        $count = $recovery->recover($minutes, $execute);

        $this->info(($execute ? 'recovered' : 'dry-run').' automation_runs='.$count.' minutes='.$minutes);

        return self::SUCCESS;
    }
}
