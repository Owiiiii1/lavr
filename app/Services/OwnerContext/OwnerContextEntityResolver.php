<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextScopeType;
use App\Models\Organization;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectNameNormalizer;

/**
 * Links a label to an existing directory row. Never creates one.
 */
final class OwnerContextEntityResolver
{
    public function resolve(User $user, OwnerContextScopeType $type, ?string $label): ?int
    {
        if (! $type->needsEntity() || $label === null || trim($label) === '') {
            return null;
        }

        $normalized = ProjectNameNormalizer::normalize($label);

        return match ($type) {
            OwnerContextScopeType::Person => $this->person($user, $label, $normalized),
            OwnerContextScopeType::Project => Project::query()
                ->where('user_id', $user->id)
                ->where('normalized_name', $normalized)
                ->value('id'),
            OwnerContextScopeType::Organization => Organization::query()
                ->where('user_id', $user->id)
                ->where('normalized_name', $normalized)
                ->value('id'),
            default => null,
        };
    }

    private function person(User $user, string $label, string $normalized): ?int
    {
        $byName = Person::query()
            ->where('user_id', $user->id)
            ->where('normalized_name', $normalized)
            ->value('id');

        if ($byName !== null) {
            return (int) $byName;
        }

        $email = mb_strtolower(trim($label));

        if (! str_contains($email, '@')) {
            return null;
        }

        $byEmail = Person::query()
            ->where('user_id', $user->id)
            ->where('primary_email', $email)
            ->value('id');

        if ($byEmail !== null) {
            return (int) $byEmail;
        }

        $identityId = PersonIdentity::query()
            ->where('normalized_value', $email)
            ->whereHas('person', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->value('person_id');

        return $identityId !== null ? (int) $identityId : null;
    }
}
