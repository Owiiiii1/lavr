<?php

namespace App\Policies;

use App\Models\LeadershipReview;
use App\Models\User;

class LeadershipReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->isOwner();
    }

    public function view(User $user, LeadershipReview $leadershipReview): bool
    {
        return $this->viewAny($user) && (int) $leadershipReview->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, LeadershipReview $leadershipReview): bool
    {
        return $this->view($user, $leadershipReview);
    }
}
