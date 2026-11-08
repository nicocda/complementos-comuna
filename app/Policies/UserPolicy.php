<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

/**
 * Users are deactivated, never deleted, so the audit trail keeps its authors.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::USERS_MANAGE);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(Permissions::USERS_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::USERS_MANAGE);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permissions::USERS_MANAGE);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
