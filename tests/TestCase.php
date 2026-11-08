<?php

namespace Tests;

use App\Models\Driver;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * A driver user with its linked Driver record.
     *
     * @return array{0: User, 1: Driver}
     */
    protected function driverUser(array $attributes = []): array
    {
        $user = $this->userWithRole(Permissions::ROLE_DRIVER, $attributes);
        $driver = Driver::factory()->create(['user_id' => $user->getKey()]);

        return [$user, $driver];
    }
}
