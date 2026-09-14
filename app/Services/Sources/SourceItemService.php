<?php

namespace App\Services\Sources;

use App\Enums\SourceItemType;
use App\Models\SourceItem;
use App\Models\User;
use Carbon\CarbonImmutable;

final class SourceItemService
{
    /**
     * @param  array{
     *     integration_account_id?: int|null,
     *     source_instance: string,
     *     external_id: string,
     *     thread_id?: ?string,
     *     occurred_at?: ?CarbonImmutable|string,
     *     person_id?: ?int,
     *     project_id?: ?int,
     *     subject?: ?string,
     *     snippet?: ?string,
     *     metadata?: array<string, mixed>,
     *     content_hash?: ?string
     * }  $payload
     */
    public function upsert(User $user, SourceItemType $type, array $payload): SourceItem
    {
        $externalId = trim((string) ($payload['external_id'] ?? ''));
        $instance = trim((string) ($payload['source_instance'] ?? ''));

        $item = SourceItem::query()->firstOrNew([
            'user_id' => $user->id,
            'source_type' => $type->value,
            'source_instance' => $instance,
            'external_id' => $externalId,
        ]);

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $item->fill([
            'integration_account_id' => $payload['integration_account_id'] ?? $item->integration_account_id,
            'thread_id' => $payload['thread_id'] ?? $item->thread_id,
            'occurred_at' => $payload['occurred_at'] ?? $item->occurred_at,
            'person_id' => $payload['person_id'] ?? $item->person_id,
            'project_id' => $payload['project_id'] ?? $item->project_id,
            'subject' => $this->clip($payload['subject'] ?? $item->subject, 240),
            'snippet' => $this->clip($payload['snippet'] ?? $item->snippet, 500),
            'metadata' => $metadata !== [] ? array_merge(is_array($item->metadata) ? $item->metadata : [], $metadata) : $item->metadata,
            'content_hash' => $payload['content_hash'] ?? $item->content_hash,
        ]);
        $item->save();

        return $item->fresh() ?? $item;
    }

    public function markProcessed(SourceItem $item): SourceItem
    {
        $item->forceFill(['processed_at' => now()])->save();

        return $item->fresh() ?? $item;
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(SourceItem $item): array
    {
        return [
            'source_type' => $item->source_type instanceof SourceItemType ? $item->source_type->value : (string) $item->source_type,
            'source_instance' => $item->source_instance,
            'integration_account_id' => $item->integration_account_id,
            'external_id' => $item->external_id,
            'thread_id' => $item->thread_id,
            'occurred_at' => optional($item->occurred_at)?->toIso8601String(),
            'project_id' => $item->project_id,
            'person_id' => $item->person_id,
            'source_item_id' => $item->id,
        ];
    }

    private function clip(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
