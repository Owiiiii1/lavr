<?php

namespace App\Policies;

use App\Models\ProactiveProposal;
use App\Models\User;

class ProactiveProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->isOwner();
    }

    public function view(User $user, ProactiveProposal $proposal): bool
    {
        return $this->viewAny($user) && (int) $proposal->user_id === (int) $user->id;
    }

    public function update(User $user, ProactiveProposal $proposal): bool
    {
        return $this->view($user, $proposal);
    }
}
