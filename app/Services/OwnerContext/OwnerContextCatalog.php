<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextItemStatus;
use App\Enums\OwnerContextSensitivity;
use App\Models\Organization;
use App\Models\OwnerContextItem;
use App\Models\OwnerContextSource;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class OwnerContextCatalog
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payload(User $user, array $filters): array
    {
        $items = $this->items($user, $filters)->limit(200)->get();
        $sources = OwnerContextSource::query()
            ->where('user_id', $user->id)
            ->withCount([
                'items',
                'items as accepted_count' => fn ($query) => $query->where('status', OwnerContextItemStatus::Accepted),
                'items as needs_review_count' => fn ($query) => $query->where('status', OwnerContextItemStatus::NeedsReview),
                'items as rejected_count' => fn ($query) => $query->where('status', OwnerContextItemStatus::Rejected),
                'items as historical_count' => fn ($query) => $query->where('fact_class', OwnerContextFactClass::Historical),
            ])
            ->latest('id')
            ->limit(50)
            ->get();

        return [
            'counts' => $this->counts($user),
            'items' => $items->map(fn (OwnerContextItem $item): array => $this->item($item))->values(),
            'sources' => $sources->map(fn (OwnerContextSource $source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'source_type' => $source->source_type->value,
                'source_date' => $source->source_date?->toDateString(),
                'status' => $source->status->value,
                'original_filename' => $source->original_filename,
                'extracted' => (int) $source->items_count,
                'accepted' => (int) $source->accepted_count,
                'needs_review' => (int) $source->needs_review_count,
                'rejected' => (int) $source->rejected_count,
                'historical' => (int) $source->historical_count,
                'duration_ms' => $source->metadata['result']['duration_ms'] ?? $source->metadata['duration_ms'] ?? null,
                'result' => $source->metadata['result'] ?? null,
                'processing' => $source->metadata['processing'] ?? null,
                'total_chunks' => $source->metadata['progress']['total_chunks'] ?? null,
                'processed_chunks' => $source->metadata['progress']['processed_chunks'] ?? null,
                'error_category' => $source->metadata['error_category'] ?? null,
            ])->values(),
            'scopes' => [
                'people' => Person::query()->where('user_id', $user->id)->orderBy('display_name')->limit(100)->get(['id', 'display_name'])
                    ->map(fn (Person $person): array => ['id' => $person->id, 'name' => $person->display_name])->values(),
                'projects' => Project::query()->where('user_id', $user->id)->orderBy('name')->limit(100)->get(['id', 'name'])
                    ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])->values(),
                'organizations' => Organization::query()->where('user_id', $user->id)->orderBy('name')->limit(100)->get(['id', 'name'])
                    ->map(fn (Organization $organization): array => ['id' => $organization->id, 'name' => $organization->name])->values(),
            ],
            'filters' => $filters,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        $base = OwnerContextItem::query()->where('user_id', $user->id);

        return [
            'accepted' => (clone $base)->where('status', OwnerContextItemStatus::Accepted)->count(),
            'candidates' => (clone $base)->where('status', OwnerContextItemStatus::Candidate)->count(),
            'needs_review' => (clone $base)->where('status', OwnerContextItemStatus::NeedsReview)->count(),
            'historical' => (clone $base)->where('fact_class', OwnerContextFactClass::Historical)->count(),
            'private_or_restricted' => (clone $base)->whereIn('sensitivity', [
                OwnerContextSensitivity::Private,
                OwnerContextSensitivity::Restricted,
            ])->count(),
            'sources' => OwnerContextSource::query()->where('user_id', $user->id)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OwnerContextItem>
     */
    private function items(User $user, array $filters): Builder
    {
        $tab = (string) ($filters['tab'] ?? 'overview');
        $query = OwnerContextItem::query()->where('user_id', $user->id)->latest('id');

        $categories = match ($tab) {
            'profile' => [OwnerContextCategory::Identity->value, OwnerContextCategory::Communication->value],
            'business' => [OwnerContextCategory::BusinessContext->value, OwnerContextCategory::BusinessRule->value, OwnerContextCategory::RoleContext->value],
            'goals' => [OwnerContextCategory::CeoGoal->value],
            'rules' => [OwnerContextCategory::CeoOperatingRule->value],
            'development' => [OwnerContextCategory::CeoDevelopment->value],
            default => [],
        };

        if ($tab === 'review') {
            $query->where('status', OwnerContextItemStatus::NeedsReview);
        } elseif ($categories !== []) {
            $query->whereIn('category', $categories);
        }

        foreach (['fact_class' => 'fact_class', 'category' => 'category', 'scope_type' => 'scope_type', 'status' => 'status', 'sensitivity' => 'sensitivity'] as $input => $column) {
            if (! empty($filters[$input])) {
                $query->where($column, $filters[$input]);
            }
        }

        if (! empty($filters['source_id'])) {
            $query->where('source_id', (int) $filters['source_id']);
        }

        if (! empty($filters['q'])) {
            $query->where('value', 'like', '%'.str_replace(['%', '_'], '', (string) $filters['q']).'%');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(OwnerContextItem $item): array
    {
        return [
            'id' => $item->id,
            'source_id' => $item->source_id,
            'value' => $item->value,
            'category' => $item->category->value,
            'fact_class' => $item->fact_class->value,
            'scope_type' => $item->scope_type->value,
            'scope_id' => $item->scope_id,
            'scope_label' => $item->metadata['scope_label'] ?? null,
            'status' => $item->status->value,
            'sensitivity' => $item->sensitivity->value,
            'confidence' => $item->confidence,
            'evidence_excerpt' => $item->evidence_excerpt,
            'effective_from' => $item->effective_from?->toDateString(),
            'effective_to' => $item->effective_to?->toDateString(),
            'supersedes_id' => $item->supersedes_id,
            'source_name' => $item->source_reference['source_name'] ?? null,
        ];
    }
}
