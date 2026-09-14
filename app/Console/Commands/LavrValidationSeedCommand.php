<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Validation\ValidationSeedService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('lavr:validation-seed {--user=} {--apply}')]
#[Description('Create tagged synthetic validation data (dry-run by default)')]
class LavrValidationSeedCommand extends Command
{
    public function handle(ValidationSeedService $seed): int
    {
        $userId = (int) $this->option('user');
        $user = $userId > 0
            ? User::query()->find($userId)
            : User::query()->where('role', 'owner')->first();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('Owner user not found.');
        }

        $result = $seed->seed($user, (bool) $this->option('apply'));
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
