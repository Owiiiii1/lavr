<?php

namespace App\Policies;

use App\Models\OwnerContextSource;
use App\Models\User;

class OwnerContextSourcePolicy
{
    public function view(User $user, OwnerContextSource $source): bool
    {
        return $this->owns($user, (int) $source->user_id);
    }

    public function update(User $user, OwnerContextSource $source): bool
    {
        return $this->owns($user, (int) $source->user_id);
    }

    private function owns(User $user, int $ownerId): bool
    {
        return $user->isActive() && (int) $user->id === $ownerId;
    }
}
