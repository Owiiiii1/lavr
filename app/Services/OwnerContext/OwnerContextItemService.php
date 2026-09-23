<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextItemStatus;
use App\Enums\OwnerContextScopeType;
use App\Enums\OwnerContextSensitivity;
use App\Enums\OwnerContextSourceStatus;
use App\Enums\OwnerContextSourceType;
use App\Models\Organization;
use App\Models\OwnerContextItem;
use App\Models\OwnerContextSource;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use App\Services\OwnerContext\DTO\OwnerContextCandidate;

final class OwnerContextItemService
{
    public function __construct(
        private readonly OwnerContextStore $store,
        private readonly OwnerContextEntityResolver $resolver,
        private readonly OwnerContextAcceptancePolicy $policy,
    ) {}

    /**
     * @param  array{value: string, category: string, fact_class: string, scope_type: string, scope_label?: ?string, scope_id?: ?int, sensitivity: string, effective_from?: ?string}  $fields
     */
    public function addManual(User $user, array $fields): OwnerContextItem
    {
        $source = OwnerContextSource::query()->create([
            'user_id' => $user->id,
            'name' => 'Manual entry',
            'source_type' => OwnerContextSourceType::Manual,
            'source_date' => $fields['effective_from'] ?? now()->toDateString(),
            'status' => OwnerContextSourceStatus::Ready,
            'metadata' => ['source_type' => 'manual'],
        ]);

        $scopeType = OwnerContextScopeType::from($fields['scope_type']);
        $label = $fields['scope_label'] ?? null;
        $scopeId = isset($fields['scope_id']) ? (int) $fields['scope_id'] : null;

        if ($scopeId !== null && ! $this->ownsScope($user, $scopeType, $scopeId)) {
            $scopeId = null;
        }

        if ($scopeId === null && $label) {
            $scopeId = $this->resolver->resolve($user, $scopeType, $label);
        }

        $candidate = new OwnerContextCandidate(
            value: trim($fields['value']),
            category: OwnerContextCategory::from($fields['category']),
            factClass: OwnerContextFactClass::from($fields['fact_class']),
            scopeType: $scopeType,
            scopeLabel: $label,
            sensitivity: OwnerContextSensitivity::from($fields['sensitivity']),
            confidence: 0.95,
            evidenceExcerpt: mb_substr(trim($fields['value']), 0, 180),
        );

        $this->store->save($source, [$candidate]);

        $item = OwnerContextItem::query()->where('source_id', $source->id)->firstOrFail();

        if ($scopeId !== null && $item->scope_id === null && $this->ownsScope($user, $scopeType, $scopeId)) {
            $item->forceFill(['scope_id' => $scopeId])->save();
        }

        if (! empty($fields['effective_from'])) {
            $item->forceFill(['effective_from' => $fields['effective_from']])->save();
        }

        return $item->fresh();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function edit(User $user, OwnerContextItem $item, array $fields): OwnerContextItem
    {
        $this->owned($user, $item);

        if ($item->status === OwnerContextItemStatus::Accepted && $this->material($item, $fields)) {
            $item->forceFill([
                'status' => OwnerContextItemStatus::Superseded,
                'effective_to' => now()->toDateString(),
            ])->save();

            $created = $this->addManual($user, [
                'value' => (string) ($fields['value'] ?? $item->value),
                'category' => (string) ($fields['category'] ?? $item->category->value),
                'fact_class' => (string) ($fields['fact_class'] ?? $item->fact_class->value),
                'scope_type' => (string) ($fields['scope_type'] ?? $item->scope_type->value),
                'scope_label' => $fields['scope_label'] ?? ($item->metadata['scope_label'] ?? null),
                'scope_id' => $fields['scope_id'] ?? $item->scope_id,
                'sensitivity' => (string) ($fields['sensitivity'] ?? $item->sensitivity->value),
                'effective_from' => now()->toDateString(),
            ]);
            $created->forceFill(['supersedes_id' => $item->id])->save();

            return $created->fresh();
        }

        $item->fill([
            'value' => $fields['value'] ?? $item->value,
            'sensitivity' => $fields['sensitivity'] ?? $item->sensitivity->value,
            'evidence_excerpt' => array_key_exists('evidence_excerpt', $fields)
                ? mb_substr((string) $fields['evidence_excerpt'], 0, 280)
                : $item->evidence_excerpt,
        ]);
        $item->save();

        return $item;
    }

    public function accept(User $user, OwnerContextItem $item): OwnerContextItem
    {
        $this->owned($user, $item);
        $item->forceFill(['status' => OwnerContextItemStatus::Accepted])->save();

        return $item;
    }

    public function reject(User $user, OwnerContextItem $item): OwnerContextItem
    {
        $this->owned($user, $item);
        $item->forceFill(['status' => OwnerContextItemStatus::Rejected])->save();

        return $item;
    }

    public function markNeedsReview(User $user, OwnerContextItem $item): OwnerContextItem
    {
        $this->owned($user, $item);
        $item->forceFill(['status' => OwnerContextItemStatus::NeedsReview])->save();

        return $item;
    }

    public function link(User $user, OwnerContextItem $item, OwnerContextScopeType $type, int $scopeId): OwnerContextItem
    {
        $this->owned($user, $item);

        if (! $this->ownsScope($user, $type, $scopeId)) {
            return $item;
        }

        $item->forceFill([
            'scope_type' => $type,
            'scope_id' => $scopeId,
        ])->save();

        return $item;
    }

    public function supersede(User $user, OwnerContextItem $current, OwnerContextItem $previous): OwnerContextItem
    {
        $this->owned($user, $current);
        $this->owned($user, $previous);

        $date = now()->toDateString();
        $previous->forceFill([
            'status' => OwnerContextItemStatus::Superseded,
            'effective_to' => $date,
        ])->save();
        $current->forceFill([
            'status' => OwnerContextItemStatus::Accepted,
            'supersedes_id' => $previous->id,
            'effective_from' => $current->effective_from ?? $date,
        ])->save();

        return $current->fresh();
    }

    /**
     * @param  list<int>  $ids
     * @return array{accepted: int, skipped: int}
     */
    public function acceptSafe(User $user, array $ids = []): array
    {
        $query = OwnerContextItem::query()
            ->where('user_id', $user->id)
            ->where('status', OwnerContextItemStatus::Candidate);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $accepted = 0;
        $skipped = 0;

        foreach ($query->get() as $item) {
            $candidate = $this->candidateFrom($item);
            $conflict = $this->hasConflict($user, $item);
            $decision = $this->policy->decide($candidate, $conflict, $item->scope_id);

            if ($decision !== OwnerContextItemStatus::Accepted) {
                $skipped++;

                continue;
            }

            $item->forceFill(['status' => OwnerContextItemStatus::Accepted])->save();
            $accepted++;
        }

        return ['accepted' => $accepted, 'skipped' => $skipped];
    }

    /**
     * @param  list<int>  $ids
     */
    public function rejectSelected(User $user, array $ids): int
    {
        return OwnerContextItem::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->where('status', '!=', OwnerContextItemStatus::Superseded)
            ->update(['status' => OwnerContextItemStatus::Rejected->value]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function material(OwnerContextItem $item, array $fields): bool
    {
        if (isset($fields['value']) && OwnerContextNormalizer::value((string) $fields['value']) !== OwnerContextNormalizer::value((string) $item->value)) {
            return true;
        }

        foreach (['category', 'fact_class', 'scope_type'] as $key) {
            if (isset($fields[$key]) && (string) $fields[$key] !== $item->{$key}->value) {
                return true;
            }
        }

        if (array_key_exists('scope_id', $fields) && (int) $fields['scope_id'] !== (int) $item->scope_id) {
            return true;
        }

        return false;
    }

    private function candidateFrom(OwnerContextItem $item): OwnerContextCandidate
    {
        return new OwnerContextCandidate(
            value: (string) $item->value,
            category: $item->category,
            factClass: $item->fact_class,
            scopeType: $item->scope_type,
            scopeLabel: $item->metadata['scope_label'] ?? null,
            sensitivity: $item->sensitivity,
            confidence: $item->confidence,
            evidenceExcerpt: $item->evidence_excerpt,
        );
    }

    private function hasConflict(User $user, OwnerContextItem $item): bool
    {
        if (! in_array($item->fact_class, [OwnerContextFactClass::Fact, OwnerContextFactClass::Current], true)) {
            return false;
        }

        return OwnerContextItem::query()
            ->where('user_id', $user->id)
            ->where('category', $item->category)
            ->where('scope_type', $item->scope_type)
            ->where('status', OwnerContextItemStatus::Accepted)
            ->whereIn('fact_class', [OwnerContextFactClass::Fact, OwnerContextFactClass::Current])
            ->where('id', '!=', $item->id)
            ->when(
                $item->scope_id === null,
                fn ($query) => $query->whereNull('scope_id'),
                fn ($query) => $query->where('scope_id', $item->scope_id),
            )
            ->where('fingerprint', '!=', $item->fingerprint)
            ->exists();
    }

    private function ownsScope(User $user, OwnerContextScopeType $type, int $scopeId): bool
    {
        $model = match ($type) {
            OwnerContextScopeType::Person => Person::query(),
            OwnerContextScopeType::Project => Project::query(),
            OwnerContextScopeType::Organization => Organization::query(),
            default => null,
        };

        if ($model === null) {
            return false;
        }

        return $model->where('user_id', $user->id)->whereKey($scopeId)->exists();
    }

    private function owned(User $user, OwnerContextItem $item): void
    {
        if ((int) $item->user_id !== (int) $user->id) {
            abort(404);
        }
    }
}
