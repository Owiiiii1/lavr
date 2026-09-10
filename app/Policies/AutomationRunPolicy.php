<?php

namespace App\Policies;

use App\Models\AutomationRun;
use App\Models\User;

class AutomationRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->isOwner();
    }

    public function view(User $user, AutomationRun $run): bool
    {
        return $this->viewAny($user) && (int) $run->user_id === (int) $user->id;
    }

    public function update(User $user, AutomationRun $run): bool
    {
        return $this->view($user, $run);
    }
}
