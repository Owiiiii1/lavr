<?php

namespace App\Services\Sources\Connectors;

use App\Models\User;

/**
 * Reference connector for future Bitrix / dashboard / REST APIs.
 * Does not scrape and does not call a live third-party system in Phase 10.
 */
final class MockExternalConnector implements BusinessSourceConnector
{
    public function key(): string
    {
        return 'external_api';
    }

    public function health(User $user): array
    {
        return [
            'health' => 'disabled',
            'freshness' => [],
            'diagnostic' => 'External API connectors are prepared; no live Bitrix connection in Phase 10.',
        ];
    }

    public function collect(User $user, array $options = []): array
    {
        return [];
    }

    public function normalize(User $user, array $raw): array
    {
        return [
            'external_id' => $raw['id'] ?? null,
            'subject' => $raw['title'] ?? $raw['subject'] ?? null,
            'occurred_at' => $raw['occurred_at'] ?? null,
        ];
    }

    public function resolveIdentities(User $user, array $raw): array
    {
        return [
            'person_id' => isset($raw['person_id']) ? (int) $raw['person_id'] : null,
            'unresolved' => empty($raw['person_id']),
        ];
    }

    public function sourceReference(array $raw): array
    {
        return [
            'source_type' => 'external_api',
            'connector' => $this->key(),
            'external_id' => $raw['id'] ?? null,
        ];
    }
}
