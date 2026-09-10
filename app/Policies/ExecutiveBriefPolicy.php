<?php

namespace App\Policies;

use App\Models\ExecutiveBrief;
use App\Models\User;

class ExecutiveBriefPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->isOwner();
    }

    public function view(User $user, ExecutiveBrief $executiveBrief): bool
    {
        return $this->viewAny($user) && (int) $executiveBrief->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ExecutiveBrief $executiveBrief): bool
    {
        return $this->view($user, $executiveBrief);
    }
}
