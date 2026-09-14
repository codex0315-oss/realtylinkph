<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgentReview;
use App\Models\User;

class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AgentReview $agentReview): bool
    {
        return $agentReview->is_visible
            || $agentReview->buyer_id === $user->id
            || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role_type === 'buyer';
    }

    public function update(User $user, AgentReview $agentReview): bool
    {
        return $agentReview->buyer_id === $user->id;
    }

    public function delete(User $user, AgentReview $agentReview): bool
    {
        return $agentReview->buyer_id === $user->id || $user->isAdmin();
    }

    public function toggleVisibility(User $user, AgentReview $agentReview): bool
    {
        return $user->isAdmin();
    }
}
