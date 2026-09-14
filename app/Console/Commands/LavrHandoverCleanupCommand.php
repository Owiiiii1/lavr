<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Handover\HandoverCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('lavr:handover-cleanup {--user=} {--integration=} {--batch=} {--failed-jobs} {--logs} {--execute} {--confirm=}')]
#[Description('Plan or execute guarded handover cleanup (dry-run by default)')]
class LavrHandoverCleanupCommand extends Command
{
    public function handle(HandoverCleanupService $cleanup): int
    {
        $user = $this->owner();
        $selectors = [
            'integration' => $this->option('integration') ? (int) $this->option('integration') : null,
            'batch' => $this->option('batch') ? (string) $this->option('batch') : null,
            'failed_jobs' => (bool) $this->option('failed-jobs'),
            'logs' => (bool) $this->option('logs'),
        ];

        try {
            $plan = $cleanup->run(
                $user,
                $selectors,
                (bool) $this->option('execute'),
                $this->option('confirm') !== null ? (string) $this->option('confirm') : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($plan['dry_run'] ?? true) {
            $this->warn('DRY RUN. Nothing deleted. Backup first, then --execute --confirm=HANDOVER');
        }

        return self::SUCCESS;
    }

    private function owner(): User
    {
        $userId = (int) $this->option('user');
        $user = $userId > 0
            ? User::query()->find($userId)
            : User::query()->where('role', 'owner')->first();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('Owner user not found.');
        }

        return $user;
    }
}
