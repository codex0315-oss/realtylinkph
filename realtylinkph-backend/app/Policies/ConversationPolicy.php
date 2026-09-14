<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->buyer_id === $user->id
            || $conversation->agent_id === $user->id
            || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return in_array($user->role_type, ['buyer', 'ghost_buyer', 'agent'], true);
    }

    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $conversation->buyer_id === $user->id
            || $conversation->agent_id === $user->id;
    }

    /** Participants only — admins read threads but don't tidy other people's inboxes. */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->buyer_id === $user->id
            || $conversation->agent_id === $user->id;
    }
}
