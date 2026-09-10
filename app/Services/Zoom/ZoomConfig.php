<?php

namespace App\Services\Zoom;

final class ZoomConfig
{
    public static function oauthTokenUrl(): string
    {
        return rtrim((string) config('zoom.oauth_token_url', 'https://zoom.us/oauth/token'), '/');
    }

    public static function apiBaseUrl(): string
    {
        return rtrim((string) config('zoom.api_base_url', 'https://api.zoom.us/v2'), '/');
    }

    public static function timeout(): int
    {
        return max(3, (int) config('zoom.timeout', 15));
    }

    public static function connectTimeout(): int
    {
        return max(1, (int) config('zoom.connect_timeout', 5));
    }

    public static function downloadTimeout(): int
    {
        return max(5, (int) config('zoom.download_timeout', 30));
    }

    public static function tokenSkewSeconds(): int
    {
        return max(10, (int) config('zoom.token_skew_seconds', 60));
    }

    public static function replayWindowSeconds(): int
    {
        return max(30, (int) config('zoom.replay_window_seconds', 300));
    }

    public static function maxWebhookBytes(): int
    {
        return max(1024, (int) config('zoom.max_webhook_bytes', 262144));
    }

    public static function queue(): string
    {
        return (string) config('zoom.queue', 'default');
    }

    public static function jobTimeout(): int
    {
        return max(30, (int) config('zoom.job_timeout', 120));
    }

    public static function jobTries(): int
    {
        return max(1, (int) config('zoom.job_tries', 6));
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        $items = config('zoom.scopes', []);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $items,
        )));
    }

    /**
     * @return list<string>
     */
    public static function trustedDownloadHosts(): array
    {
        $items = config('zoom.trusted_download_hosts', []);

        return is_array($items)
            ? array_values(array_filter(array_map(
                static fn ($value): string => strtolower(trim((string) $value)),
                $items,
            )))
            : [];
    }

    /**
     * @return list<string>
     */
    public static function trustedDownloadHostSuffixes(): array
    {
        $items = config('zoom.trusted_download_host_suffixes', []);

        return is_array($items)
            ? array_values(array_filter(array_map(
                static fn ($value): string => strtolower(trim((string) $value)),
                $items,
            )))
            : [];
    }

    public static function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/zoom';
    }
}
