<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Reminders\PushPayloadBuilder;
use App\Support\WorkspaceUrl;

final class NotificationUrlPolicy
{
    public function __construct(
        private readonly PushPayloadBuilder $payloads = new PushPayloadBuilder,
    ) {}

    public function workspacePath(User $user, ?string $query = null, ?int $conversationId = null): string
    {
        return WorkspaceUrl::path($conversationId, $query);
    }

    public function isSafe(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true;
        }

        return $this->payloads->isAllowlisted($url);
    }

    public function sanitize(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return $this->isSafe($url) ? $url : null;
    }
}
