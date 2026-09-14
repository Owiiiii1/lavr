<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Handover\HandoverCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('lavr:validation-cleanup {batch} {--user=} {--execute} {--confirm=}')]
#[Description('Remove a synthetic validation batch (dry-run by default)')]
class LavrValidationCleanupCommand extends Command
{
    public function handle(HandoverCleanupService $cleanup): int
    {
        $userId = (int) $this->option('user');
        $user = $userId > 0
            ? User::query()->find($userId)
            : User::query()->where('role', 'owner')->first();

        if (! $user instanceof User) {
            $this->error('Owner user not found.');

            return self::FAILURE;
        }

        try {
            $plan = $cleanup->run(
                $user,
                ['batch' => (string) $this->argument('batch')],
                (bool) $this->option('execute'),
                $this->option('confirm') !== null ? (string) $this->option('confirm') : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
