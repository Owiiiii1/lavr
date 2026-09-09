<?php

namespace App\Services\Meetings;

final class MeetingConfig
{
    public static function disk(): string
    {
        return (string) config('meetings.disk', 'local');
    }

    public static function directory(): string
    {
        return trim((string) config('meetings.directory', 'meetings'), '/');
    }

    public static function queue(): string
    {
        return (string) config('meetings.queue', 'analysis');
    }

    public static function maxFileSizeMb(): int
    {
        return max(1, (int) config('meetings.max_file_size_mb', 8));
    }

    public static function maxFileSizeBytes(): int
    {
        return self::maxFileSizeMb() * 1024 * 1024;
    }

    public static function maxPasteChars(): int
    {
        return max(1000, (int) config('meetings.max_paste_chars', 1_500_000));
    }

    public static function chunkChars(): int
    {
        return max(1000, (int) config('meetings.chunk_chars', 6000));
    }

    public static function chunkOverlapChars(): int
    {
        return max(0, min(self::chunkChars() - 1, (int) config('meetings.chunk_overlap_chars', 200)));
    }

    public static function maxChunks(): int
    {
        return max(1, (int) config('meetings.max_chunks', 12));
    }

    public static function chunkAiRetries(): int
    {
        return max(1, (int) config('meetings.chunk_ai_retries', 2));
    }

    public static function maxEvidenceChars(): int
    {
        return max(40, (int) config('meetings.max_evidence_chars', 280));
    }

    public static function promptVersion(): string
    {
        return (string) config('meetings.prompt_version', 'meeting-intelligence-v1');
    }

    public static function jobTimeout(): int
    {
        return max(30, (int) config('meetings.job_timeout', 180));
    }

    /**
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        $items = config('meetings.allowed_extensions', ['txt', 'vtt', 'srt', 'md']);

        if (! is_array($items)) {
            return ['txt', 'vtt', 'srt', 'md'];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $items,
        )));
    }

    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        $items = config('meetings.allowed_mime_types', []);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $items,
        )));
    }
}
