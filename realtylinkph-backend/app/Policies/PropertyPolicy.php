<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Property $property): bool
    {
        if ($property->status === 'published') {
            return true;
        }

        return $property->agent_id === $user->id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isVerifiedAgent();
    }

    public function update(User $user, Property $property): bool
    {
        return $property->agent_id === $user->id || $user->isAdmin();
    }

    public function delete(User $user, Property $property): bool
    {
        return $property->agent_id === $user->id || $user->isAdmin();
    }

    public function managePhotos(User $user, Property $property): bool
    {
        return $property->agent_id === $user->id || $user->isAdmin();
    }
}
