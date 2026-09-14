<?php

namespace App\Services\Telegram\WebApp;

final class TelegramWebAppDeepLink
{
    public const DEFAULT_PATH = '/lavr/today';

    /**
     * Map a Telegram start_param (64 chars, [A-Za-z0-9_-]) or an internal next path
     * onto an allowlisted LAVR path. External URLs are rejected.
     */
    public function resolve(?string $startParam = null, ?string $next = null): string
    {
        if (is_string($next) && $next !== '') {
            $fromNext = $this->sanitizePath($next);

            if ($fromNext !== null) {
                return $fromNext;
            }
        }

        $token = trim((string) $startParam);

        if ($token === '') {
            return self::DEFAULT_PATH;
        }

        if (preg_match('/^chat_(\d+)$/', $token, $matches) === 1) {
            return $this->assertAllowlisted('/lavr/chats/'.$matches[1]);
        }

        if (preg_match('/^project_(\d+)$/', $token, $matches) === 1) {
            return $this->assertAllowlisted('/lavr/projects/'.$matches[1]);
        }

        if (preg_match('/^meeting_(\d+)$/', $token, $matches) === 1) {
            return $this->assertAllowlisted('/lavr/meetings/'.$matches[1]);
        }

        if (preg_match('/^commitment_(\d+)$/', $token, $matches) === 1) {
            return $this->assertAllowlisted('/lavr/commitments/'.$matches[1]);
        }

        if (preg_match('/^brief_(\d+)$/', $token, $matches) === 1) {
            return $this->assertAllowlisted('/lavr/briefs/'.$matches[1]);
        }

        if (preg_match('/^leadership_(\d+)$/', $token, $matches) === 1) {
            return $this->assertAllowlisted('/lavr/leadership/'.$matches[1]);
        }

        if (preg_match('/^proactive_(\d+)$/', $token, $matches) === 1) {
            return $this->assertAllowlisted('/lavr/proactive/'.$matches[1]);
        }

        $mapped = match ($token) {
            'today' => '/lavr/today',
            'chat', 'chats' => '/lavr',
            'people' => '/lavr/people',
            'projects' => '/lavr/projects',
            'more' => '/lavr/more',
            'notifications' => '/lavr/notifications',
            'reports' => '/lavr/reports',
            'knowledge' => '/lavr?settings=knowledge',
            'settings' => '/lavr?settings=profile',
            'meetings' => '/lavr/meetings',
            'commitments' => '/lavr/commitments',
            'brief', 'briefs' => '/lavr/briefs',
            'leadership' => '/lavr/leadership',
            'proactive' => '/lavr/proactive',
            default => null,
        };

        return $mapped ?? self::DEFAULT_PATH;
    }

    public function isAllowlisted(string $path): bool
    {
        return $this->sanitizePath($path) !== null;
    }

    public function sanitizePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
            return null;
        }

        $parsed = parse_url($path);

        if (! is_array($parsed) || isset($parsed['host']) || isset($parsed['scheme'])) {
            return null;
        }

        $normalized = '/'.ltrim((string) ($parsed['path'] ?? ''), '/');

        if ($normalized !== '/' && str_ends_with($normalized, '/')) {
            $normalized = rtrim($normalized, '/');
        }

        if (! $this->matchesAllowlist($normalized)) {
            return null;
        }

        $query = $parsed['query'] ?? '';

        if ($query === '') {
            return $normalized;
        }

        if (! $this->queryIsSafe($query)) {
            return null;
        }

        return $normalized.'?'.$query;
    }

    private function assertAllowlisted(string $path): string
    {
        return $this->sanitizePath($path) ?? self::DEFAULT_PATH;
    }

    private function matchesAllowlist(string $path): bool
    {
        $exact = [
            '/lavr',
            '/lavr/today',
            '/lavr/people',
            '/lavr/more',
            '/lavr/projects',
            '/lavr/notifications',
            '/lavr/reports',
            '/lavr/meetings',
            '/lavr/commitments',
            '/lavr/briefs',
            '/lavr/leadership',
            '/lavr/proactive',
        ];

        if (in_array($path, $exact, true)) {
            return true;
        }

        return (bool) preg_match(
            '#^/lavr/(chats|projects|commitments|meetings|briefs|leadership|proactive)/\d+$#',
            $path,
        );
    }

    private function queryIsSafe(string $query): bool
    {
        parse_str($query, $params);

        if ($params === []) {
            return false;
        }

        $allowed = ['notifications', 'reports', 'task', 'reminder', 'watchers', 'settings'];

        foreach (array_keys($params) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
