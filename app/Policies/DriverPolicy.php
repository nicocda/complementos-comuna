<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;
use App\Support\Permissions;

class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::FLEET_VIEW);
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->can(Permissions::FLEET_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::FLEET_MANAGE);
    }

    public function update(User $user, Driver $driver): bool
    {
        return $user->can(Permissions::FLEET_MANAGE);
    }

    /**
     * Drivers with repair history cannot be deleted; deactivate them instead.
     */
    public function delete(User $user, Driver $driver): bool
    {
        return $user->can(Permissions::FLEET_MANAGE) && ! $driver->repairRequests()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
