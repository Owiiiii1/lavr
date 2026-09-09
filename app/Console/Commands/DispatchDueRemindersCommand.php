<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatchService;
use Illuminate\Console\Command;

class DispatchDueRemindersCommand extends Command
{
    protected $signature = 'jarvis:reminders:dispatch {--limit=25}';

    protected $description = 'Dispatch due LAVR Core reminders';

    public function handle(ReminderDispatchService $dispatch): int
    {
        $count = $dispatch->dispatchDue((int) $this->option('limit'));

        $this->info('Dispatched '.$count.' reminder(s).');

        return self::SUCCESS;
    }
}
