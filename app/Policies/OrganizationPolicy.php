<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\UserCapability;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::PEOPLE);
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->owns($user, $organization);
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::PEOPLE);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->owns($user, $organization);
    }

    public function archive(User $user, Organization $organization): bool
    {
        return $this->owns($user, $organization);
    }

    private function owns(User $user, Organization $organization): bool
    {
        return $user->isActive()
            && $user->canUseCapability(UserCapability::PEOPLE)
            && (int) $organization->user_id === (int) $user->id;
    }
}
