<?php

namespace App\Console\Commands;

use App\Services\Productivity\TaskDueDispatchService;
use Illuminate\Console\Command;

class DispatchTaskDueNotificationsCommand extends Command
{
    protected $signature = 'jarvis:tasks:dispatch {--limit=80}';

    protected $description = 'Create in-app notifications for due and overdue LAVR tasks';

    public function handle(TaskDueDispatchService $dispatch): int
    {
        $count = $dispatch->dispatchDue((int) $this->option('limit'));

        $this->info('Recorded '.$count.' task due/overdue notification(s).');

        return self::SUCCESS;
    }
}
