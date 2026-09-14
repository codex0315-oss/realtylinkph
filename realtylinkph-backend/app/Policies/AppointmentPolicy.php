<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $appointment->buyer_id === $user->id
            || $appointment->agent_id === $user->id
            || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return in_array($user->role_type, ['buyer', 'ghost_buyer'], true);
    }

    public function confirm(User $user, Appointment $appointment): bool
    {
        return $appointment->agent_id === $user->id;
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $appointment->buyer_id === $user->id
            || $appointment->agent_id === $user->id
            || $user->isAdmin();
    }

    /** Only the listing agent marks a viewing as completed/done. */
    public function complete(User $user, Appointment $appointment): bool
    {
        return $appointment->agent_id === $user->id;
    }
}
