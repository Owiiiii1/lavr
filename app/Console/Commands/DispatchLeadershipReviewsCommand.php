<?php

namespace App\Console\Commands;

use App\Services\LeadershipReview\LeadershipReviewDispatchService;
use Illuminate\Console\Command;

class DispatchLeadershipReviewsCommand extends Command
{
    protected $signature = 'leadership-reviews:dispatch {--limit=40}';

    protected $description = 'Dispatch due weekly leadership reviews in Owner local time';

    public function handle(LeadershipReviewDispatchService $dispatch): int
    {
        $count = $dispatch->dispatchDue((int) $this->option('limit'));
        $this->info('Delivered '.$count.' leadership review(s).');

        return self::SUCCESS;
    }
}
