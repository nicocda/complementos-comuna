<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityAndOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_queue_is_drained_by_the_scheduler(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

        $this->assertStringContainsString('queue:work --stop-when-empty', $commands);
    }

    public function test_admin_creates_user_with_strong_password_and_role(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN));
        $driverRole = Role::findByName(Permissions::ROLE_DRIVER);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Nuevo', 'email' => 'nuevo@example.com', 'password' => 'corta', 'roles' => [$driverRole->getKey()]])
            ->call('create')
            ->assertHasFormErrors(['password']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Nuevo', 'email' => 'nuevo@example.com', 'password' => 'unaClaveLarga2026', 'roles' => [$driverRole->getKey()]])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'nuevo@example.com')->sole();
        $this->assertTrue(Hash::check('unaClaveLarga2026', $user->password));
        $this->assertTrue($user->hasRole(Permissions::ROLE_DRIVER));
    }

    public function test_editing_without_password_keeps_the_current_one(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN));
        $user = $this->userWithRole(Permissions::ROLE_DRIVER, ['password' => 'claveOriginal2026']);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Renombrado', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('Renombrado', $user->name);
        $this->assertTrue(Hash::check('claveOriginal2026', $user->password));
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->assertFormFieldDisabled('is_active');
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Nombre y apellido', 'Admin Uno')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'unaClaveLarga2026')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.com')->sole();
        $this->assertTrue($admin->hasRole(Permissions::ROLE_ADMIN));
        $this->assertTrue($admin->is_active);
    }

    public function test_demo_users_are_not_seeded_outside_local(): void
    {
        $this->seed();

        $this->assertFalse(User::query()->where('email', 'like', '%@demo.test')->exists());
    }
}
