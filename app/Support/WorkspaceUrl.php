<?php

namespace App\Support;

/**
 * Canonical personal workspace URL for the single-client LAVR instance.
 */
final class WorkspaceUrl
{
    public const PREFIX = '/lavr';

    public static function path(?int $conversationId = null, ?string $query = null): string
    {
        $path = self::PREFIX;

        if ($conversationId !== null && $conversationId > 0) {
            $path .= '/chats/'.$conversationId;
        }

        if ($query !== null && $query !== '') {
            $path .= (str_contains($path, '?') ? '&' : '?').ltrim($query, '?');
        }

        return $path;
    }

    public static function isAllowlistedPath(string $path): bool
    {
        return $path === '/lavr'
            || $path === '/jarvis'
            || $path === '/chat'
            || str_starts_with($path, '/lavr/')
            || str_starts_with($path, '/jarvis/')
            || str_starts_with($path, '/chat/');
    }
}
