<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;

final class LavrBackupService
{
    /**
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        $directory = (string) config('readiness.backup_directory', storage_path('backups'));
        $mysqldump = trim((string) shell_exec('command -v mysqldump'));

        return [
            'dry_run' => true,
            'destination' => $directory,
            'destination_writable' => File::isDirectory($directory) ? is_writable($directory) : is_writable(dirname($directory)),
            'mysqldump' => $mysqldump !== '',
            'storage_path' => storage_path('app'),
            'env_excluded' => true,
        ];
    }
}
