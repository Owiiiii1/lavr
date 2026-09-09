<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;
use App\Services\Users\UserCapability;

class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::MEETINGS);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $this->owns($user, $meeting);
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::MEETINGS);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $this->owns($user, $meeting);
    }

    public function archive(User $user, Meeting $meeting): bool
    {
        return $this->owns($user, $meeting);
    }

    private function owns(User $user, Meeting $meeting): bool
    {
        return $user->isActive()
            && $user->canUseCapability(UserCapability::MEETINGS)
            && (int) $meeting->user_id === (int) $user->id;
    }
}
