<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use App\Models\User;
use App\Support\WorkspaceUrl;
use Illuminate\Support\Str;

final class PushPayloadBuilder
{
    public const TITLE = 'LAVR';

    /**
     * @return array{reminder_id: int, title: string, body: string, url: string, timestamp: string}
     */
    public function payload(Reminder $reminder, User $user, string $timestampIso): array
    {
        $limit = max(20, (int) config('reminders.push.payload_body_limit', 120));

        return [
            'reminder_id' => (int) $reminder->id,
            'title' => self::TITLE,
            'body' => Str::limit(trim((string) $reminder->text), $limit, '…'),
            'url' => $this->urlFor($user, $reminder),
            'timestamp' => $timestampIso,
        ];
    }

    public function urlFor(User $user, Reminder $reminder): string
    {
        $conversationId = (int) ($reminder->source_conversation_id ?? 0);

        return WorkspaceUrl::path(
            $conversationId > 0 ? $conversationId : null,
            'reminder='.(int) $reminder->id,
        );
    }

    public function isAllowlisted(string $url): bool
    {
        if ($url === '' || str_contains($url, '://') || str_starts_with($url, '//')) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        return WorkspaceUrl::isAllowlistedPath($path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isBounded(array $payload): bool
    {
        $allowed = ['reminder_id', 'title', 'body', 'url', 'timestamp'];

        foreach (array_keys($payload) as $key) {
            if (! in_array($key, $allowed, true)) {
                return false;
            }
        }

        if (! isset($payload['reminder_id'], $payload['title'], $payload['body'], $payload['url'], $payload['timestamp'])) {
            return false;
        }

        if (! $this->isAllowlisted((string) $payload['url'])) {
            return false;
        }

        $limit = max(20, (int) config('reminders.push.payload_body_limit', 120));

        return mb_strlen((string) $payload['body']) <= $limit + 1;
    }
}
