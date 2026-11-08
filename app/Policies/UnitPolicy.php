<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;
use App\Support\Permissions;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::FLEET_VIEW);
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->can(Permissions::FLEET_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::FLEET_MANAGE);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->can(Permissions::FLEET_MANAGE);
    }

    /**
     * Units with repair history cannot be deleted; mark them "De baja" instead.
     */
    public function delete(User $user, Unit $unit): bool
    {
        return $user->can(Permissions::FLEET_MANAGE) && ! $unit->repairRequests()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
