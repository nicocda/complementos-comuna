<?php

namespace Database\Seeders;

use App\Enums\UnitType;
use App\Models\Driver;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Demo data with fictitious people, only for local development.
        if (! app()->environment('local')) {
            return;
        }

        $demoUsers = [
            Permissions::ROLE_ADMIN => 'admin@demo.test',
            Permissions::ROLE_SUPERIOR => 'superior@demo.test',
            Permissions::ROLE_SUPERVISOR => 'encargado@demo.test',
            Permissions::ROLE_DRIVER => 'conductor@demo.test',
        ];

        foreach ($demoUsers as $role => $email) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => 'Demo '.Permissions::roleLabels()[$role], 'password' => 'password', 'is_active' => true],
            );
            $user->syncRoles([$role]);
        }

        $tractor = Unit::query()->firstOrCreate(
            ['internal_number' => 900],
            ['type' => UnitType::Tractor, 'name' => 'Tractor de prueba'],
        );
        Unit::query()->firstOrCreate(
            ['internal_number' => 901],
            ['type' => UnitType::Trailer, 'name' => 'Carro de prueba', 'attached_to_unit_id' => $tractor->getKey()],
        );

        $driver = Driver::query()->firstOrCreate(
            ['name' => 'Conductor de prueba'],
            ['user_id' => User::query()->where('email', 'conductor@demo.test')->value('id')],
        );
        $driver->units()->syncWithoutDetaching([$tractor->getKey()]);
    }
}
