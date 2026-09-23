<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextItemStatus;
use App\Enums\OwnerContextSensitivity;
use App\Services\OwnerContext\DTO\OwnerContextCandidate;

/**
 * Safe auto-accept, and only then:
 * - fact class is fact or current, or analysis in a coaching/rule category
 * - confidence is at least owner_context.auto_accept_confidence (a missing score is not high)
 * - sensitivity is normal
 * - scope is owner or business, or the person/project/organization id is already resolved
 * - category is not a personal constraint
 * - there is no conflict with an accepted current/fact claim
 * - this layer does not write People, Projects, or Organizations
 *
 * to_verify and historical stay candidates.
 * Conflicts and unresolved directory labels become needs_review.
 * Private and restricted stay candidates.
 */
final class OwnerContextAcceptancePolicy
{
    public function decide(OwnerContextCandidate $item, bool $conflict, ?int $scopeId): OwnerContextItemStatus
    {
        if ($conflict || ($item->scopeType->needsEntity() && $scopeId === null)) {
            return OwnerContextItemStatus::NeedsReview;
        }

        if (! $this->classAllowed($item) || ! $this->confidenceHigh($item)) {
            return OwnerContextItemStatus::Candidate;
        }

        if ($item->sensitivity !== OwnerContextSensitivity::Normal) {
            return OwnerContextItemStatus::Candidate;
        }

        if ($item->category === OwnerContextCategory::PersonalConstraint) {
            return OwnerContextItemStatus::Candidate;
        }

        return OwnerContextItemStatus::Accepted;
    }

    private function classAllowed(OwnerContextCandidate $item): bool
    {
        if ($item->factClass === OwnerContextFactClass::Fact || $item->factClass === OwnerContextFactClass::Current) {
            return true;
        }

        if ($item->factClass !== OwnerContextFactClass::Analysis) {
            return false;
        }

        return in_array($item->category, [
            OwnerContextCategory::CeoOperatingRule,
            OwnerContextCategory::CeoGoal,
            OwnerContextCategory::CeoDevelopment,
            OwnerContextCategory::BusinessRule,
        ], true);
    }

    private function confidenceHigh(OwnerContextCandidate $item): bool
    {
        if ($item->confidence === null) {
            return false;
        }

        return $item->confidence >= (float) config('owner_context.auto_accept_confidence', 0.85);
    }
}
