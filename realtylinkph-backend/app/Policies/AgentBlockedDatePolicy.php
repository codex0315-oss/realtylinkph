<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgentBlockedDate;
use App\Models\User;

class AgentBlockedDatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AgentBlockedDate $agentBlockedDate): bool
    {
        return $agentBlockedDate->agent_id === $user->id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isVerifiedAgent();
    }

    public function update(User $user, AgentBlockedDate $agentBlockedDate): bool
    {
        return $agentBlockedDate->agent_id === $user->id;
    }

    public function delete(User $user, AgentBlockedDate $agentBlockedDate): bool
    {
        return $agentBlockedDate->agent_id === $user->id || $user->isAdmin();
    }
}
