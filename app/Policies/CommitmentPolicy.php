<?php

namespace App\Policies;

use App\Models\Commitment;
use App\Models\User;
use App\Services\Users\UserCapability;

class CommitmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::COMMITMENTS);
    }

    public function view(User $user, Commitment $commitment): bool
    {
        return $this->owns($user, $commitment);
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::COMMITMENTS);
    }

    public function update(User $user, Commitment $commitment): bool
    {
        return $this->owns($user, $commitment);
    }

    private function owns(User $user, Commitment $commitment): bool
    {
        return $user->isActive()
            && $user->canUseCapability(UserCapability::COMMITMENTS)
            && (int) $commitment->user_id === (int) $user->id;
    }
}
