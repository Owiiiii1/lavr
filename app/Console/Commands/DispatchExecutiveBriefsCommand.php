<?php

namespace App\Console\Commands;

use App\Services\ExecutiveBrief\ExecutiveBriefDispatchService;
use Illuminate\Console\Command;

class DispatchExecutiveBriefsCommand extends Command
{
    protected $signature = 'executive-briefs:dispatch {--limit=40}';

    protected $description = 'Dispatch due morning executive briefs in Owner local time';

    public function handle(ExecutiveBriefDispatchService $dispatch): int
    {
        $count = $dispatch->dispatchDue((int) $this->option('limit'));
        $this->info('Delivered '.$count.' executive brief(s).');

        return self::SUCCESS;
    }
}
