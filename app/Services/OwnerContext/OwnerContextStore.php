<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextItemStatus;
use App\Enums\OwnerContextSensitivity;
use App\Models\OwnerContextItem;
use App\Models\OwnerContextSource;
use App\Models\User;
use App\Services\OwnerContext\DTO\OwnerContextCandidate;

final class OwnerContextStore
{
    public function __construct(
        private readonly OwnerContextEntityResolver $resolver,
        private readonly OwnerContextAcceptancePolicy $policy,
    ) {}

    /**
     * @param  list<OwnerContextCandidate>  $candidates
     * @return array<string, int|string>
     */
    public function save(OwnerContextSource $source, array $candidates): array
    {
        $user = $source->user ?? User::query()->findOrFail($source->user_id);
        $seen = [];
        $stats = [
            'extracted' => 0,
            'accepted' => 0,
            'needs_review' => 0,
            'candidates' => 0,
            'linked' => 0,
            'historical' => 0,
            'private_or_restricted' => 0,
            'skipped_duplicates' => 0,
        ];
        $limit = (int) config('owner_context.max_items', 400);

        foreach ($candidates as $candidate) {
            if ($stats['extracted'] >= $limit) {
                break;
            }

            $scopeId = $this->resolver->resolve($user, $candidate->scopeType, $candidate->scopeLabel);
            $fingerprint = OwnerContextNormalizer::fingerprint(
                (int) $user->id,
                $candidate->category,
                $candidate->scopeType,
                $scopeId,
                $candidate->scopeLabel,
                $candidate->factClass,
                $candidate->value,
            );

            if (isset($seen[$fingerprint]) || $this->exists($user, $fingerprint)) {
                $stats['skipped_duplicates']++;

                continue;
            }

            $seen[$fingerprint] = true;
            $conflict = $this->conflicts($user, $candidate, $scopeId, $fingerprint);
            $status = $this->policy->decide($candidate, $conflict, $scopeId);

            OwnerContextItem::query()->create([
                'user_id' => $user->id,
                'source_id' => $source->id,
                'category' => $candidate->category,
                'scope_type' => $candidate->scopeType,
                'scope_id' => $scopeId,
                'fact_class' => $candidate->factClass,
                'value' => $candidate->value,
                'status' => $status,
                'sensitivity' => $candidate->sensitivity,
                'confidence' => $candidate->confidence,
                'evidence_excerpt' => $candidate->evidenceExcerpt,
                'fingerprint' => $fingerprint,
                'effective_from' => $source->source_date?->toDateString(),
                'source_reference' => [
                    'source_id' => $source->id,
                    'source_name' => $source->name,
                ],
                'metadata' => [
                    'scope_label' => $candidate->scopeLabel,
                ],
            ]);

            $stats['extracted']++;
            $stats[$this->bucket($status)]++;

            if ($scopeId !== null) {
                $stats['linked']++;
            }

            if ($candidate->factClass === OwnerContextFactClass::Historical) {
                $stats['historical']++;
            }

            if ($candidate->sensitivity !== OwnerContextSensitivity::Normal) {
                $stats['private_or_restricted']++;
            }
        }

        return $stats;
    }

    private function exists(User $user, string $fingerprint): bool
    {
        return OwnerContextItem::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->where('status', '!=', OwnerContextItemStatus::Rejected)
            ->exists();
    }

    private function conflicts(User $user, OwnerContextCandidate $candidate, ?int $scopeId, string $fingerprint): bool
    {
        if (! in_array($candidate->factClass, [OwnerContextFactClass::Fact, OwnerContextFactClass::Current], true)) {
            return false;
        }

        $normalized = OwnerContextNormalizer::value($candidate->value);
        $rows = OwnerContextItem::query()
            ->where('user_id', $user->id)
            ->where('category', $candidate->category)
            ->where('scope_type', $candidate->scopeType)
            ->where('status', OwnerContextItemStatus::Accepted)
            ->whereIn('fact_class', [OwnerContextFactClass::Fact, OwnerContextFactClass::Current])
            ->where('fingerprint', '!=', $fingerprint)
            ->when(
                $scopeId === null,
                fn ($query) => $query->whereNull('scope_id'),
                fn ($query) => $query->where('scope_id', $scopeId),
            )
            ->get();

        foreach ($rows as $row) {
            $label = (string) ($row->metadata['scope_label'] ?? '');

            if ($scopeId === null && OwnerContextNormalizer::value($label) !== OwnerContextNormalizer::value((string) $candidate->scopeLabel)) {
                continue;
            }

            if (OwnerContextNormalizer::value((string) $row->value) !== $normalized) {
                return true;
            }
        }

        return false;
    }

    private function bucket(OwnerContextItemStatus $status): string
    {
        return match ($status) {
            OwnerContextItemStatus::Accepted => 'accepted',
            OwnerContextItemStatus::NeedsReview => 'needs_review',
            default => 'candidates',
        };
    }
}
