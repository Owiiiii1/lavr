<?php

namespace App\Services\Sources\Connectors;

use App\Models\User;
use App\Services\Sources\SourceIdentityResolver;

final class TelegramGroupConnector implements BusinessSourceConnector
{
    public function __construct(
        private readonly SourceIdentityResolver $identities,
    ) {}

    public function key(): string
    {
        return 'telegram_group';
    }

    public function health(User $user): array
    {
        return [
            'health' => 'healthy',
            'freshness' => [],
            'diagnostic' => null,
        ];
    }

    public function collect(User $user, array $options = []): array
    {
        return [];
    }

    public function normalize(User $user, array $raw): array
    {
        return [
            'external_id' => $raw['channel_message_id'] ?? $raw['id'] ?? null,
            'snippet' => $raw['body'] ?? $raw['snippet'] ?? null,
        ];
    }

    public function resolveIdentities(User $user, array $raw): array
    {
        $id = (string) ($raw['sender_external_id'] ?? '');
        $resolved = $id !== ''
            ? $this->identities->resolveTelegramUserId($user, $id)
            : ['person_id' => null, 'unresolved' => true];

        return [
            'person_id' => $resolved['person_id'],
            'unresolved' => (bool) ($resolved['unresolved'] ?? false),
        ];
    }

    public function sourceReference(array $raw): array
    {
        return [
            'source_type' => 'telegram_message',
            'telegram_group_id' => $raw['telegram_group_id'] ?? null,
            'external_id' => $raw['channel_message_id'] ?? $raw['id'] ?? null,
        ];
    }
}
