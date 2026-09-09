<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Services\Users\UserCapability;

class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::PEOPLE);
    }

    public function view(User $user, Person $person): bool
    {
        return $this->owns($user, $person);
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->canUseCapability(UserCapability::PEOPLE);
    }

    public function update(User $user, Person $person): bool
    {
        return $this->owns($user, $person);
    }

    public function archive(User $user, Person $person): bool
    {
        return $this->owns($user, $person);
    }

    private function owns(User $user, Person $person): bool
    {
        return $user->isActive()
            && $user->canUseCapability(UserCapability::PEOPLE)
            && (int) $person->user_id === (int) $user->id;
    }
}
