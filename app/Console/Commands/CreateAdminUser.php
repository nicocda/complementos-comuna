<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first administrator interactively, so no password lives in code or seeders.
 */
#[Signature('app:create-admin')]
#[Description('Crea un usuario administrador (pide los datos por consola)')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $name = text('Nombre y apellido', required: true);
        $email = text('Email', required: true, validate: fn (string $value) => Validator::make(
            ['email' => $value],
            ['email' => ['email', 'unique:users,email']],
        )->errors()->first('email') ?: null);
        $password = password('Contraseña (mínimo 12 caracteres)', required: true, validate: fn (string $value) => Validator::make(
            ['password' => $value],
            ['password' => [Password::min(12)->letters()->numbers()]],
        )->errors()->first('password') ?: null);

        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
        $user->assignRole(Permissions::ROLE_ADMIN);

        $this->components->info("Administrador {$email} creado.");

        return self::SUCCESS;
    }
}
