<?php

namespace App\Console\Commands;

use App\Services\Backup\LavrBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lavr:backup {--dry-run=1}')]
#[Description('Validate backup readiness (dry-run by default; does not dump production)')]
class LavrBackupCommand extends Command
{
    public function handle(LavrBackupService $backup): int
    {
        $result = $backup->dryRun();
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($result['destination_writable'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
