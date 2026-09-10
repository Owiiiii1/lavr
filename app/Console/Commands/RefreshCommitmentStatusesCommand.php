<?php

namespace App\Console\Commands;

use App\Services\Commitments\CommitmentService;
use Illuminate\Console\Command;

class RefreshCommitmentStatusesCommand extends Command
{
    protected $signature = 'commitments:refresh-statuses {--limit=500}';

    protected $description = 'Recompute due_soon and overdue on first-class commitments';

    public function handle(CommitmentService $commitments): int
    {
        $count = $commitments->refreshStatuses((int) $this->option('limit'));
        $this->info('Updated '.$count.' commitment status(es).');

        return self::SUCCESS;
    }
}
