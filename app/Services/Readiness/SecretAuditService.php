<?php

namespace App\Services\Readiness;

final class SecretAuditService
{
    /**
     * Scan tracked files for secret-like patterns. Never returns secret values.
     *
     * @return list<array{path: string, category: string, status: string}>
     */
    public function scanTracked(): array
    {
        $root = base_path();
        $output = [];
        $process = proc_open(['git', 'ls-files'], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);

        if (! is_resource($process)) {
            return [['path' => '.', 'category' => 'git', 'status' => 'unavailable']];
        }

        $files = explode("\n", trim((string) stream_get_contents($pipes[1])));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $patterns = [
            'private_key' => '/BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY/',
            'aws_key' => '/AKIA[0-9A-Z]{16}/',
            'generic_token' => '/(api[_-]?key|client_secret|webhook_secret|bot_token)\s*[:=]\s*[\'\"][^\'\"]{8,}/i',
        ];

        foreach ($files as $file) {
            if ($file === '' || str_ends_with($file, '.md') || str_starts_with($file, 'Docs/')) {
                continue;
            }

            $path = $root.DIRECTORY_SEPARATOR.$file;
            if (! is_file($path) || filesize($path) > 1_000_000) {
                continue;
            }

            $contents = @file_get_contents($path);
            if (! is_string($contents)) {
                continue;
            }

            foreach ($patterns as $category => $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $output[] = [
                        'path' => $file,
                        'category' => $category,
                        'status' => 'possible_secret',
                    ];
                }
            }
        }

        if ($output === []) {
            $output[] = [
                'path' => '.',
                'category' => 'tracked_tree',
                'status' => 'clear',
            ];
        }

        return $output;
    }
}
