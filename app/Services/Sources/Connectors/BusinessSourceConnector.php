<?php

namespace App\Services\Sources\Connectors;

use App\Models\User;

interface BusinessSourceConnector
{
    public function key(): string;

    /**
     * @return array{health: string, freshness: array<string, mixed>, diagnostic: ?string}
     */
    public function health(User $user): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function collect(User $user, array $options = []): array;

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(User $user, array $raw): array;

    /**
     * @param  array<string, mixed>  $raw
     * @return array{person_id: ?int, unresolved: bool}
     */
    public function resolveIdentities(User $user, array $raw): array;

    /**
     * @return array<string, mixed>
     */
    public function sourceReference(array $raw): array;
}
