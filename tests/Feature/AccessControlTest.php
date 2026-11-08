<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\RepairWorkflow;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get(route('repair-requests.print', RepairRequest::factory()->create()))->assertRedirect('/admin/login');
    }

    public function test_user_without_role_cannot_enter_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_inactive_user_cannot_enter_panel(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_ADMIN, ['is_active' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_active_user_with_role_enters_panel(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_DRIVER))->get('/admin')->assertOk();
    }

    public function test_resource_pages_respect_permissions(): void
    {
        [$driverUser] = $this->driverUser();
        $supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->actingAs($driverUser)->get('/admin/unidades')->assertForbidden();
        $this->actingAs($driverUser)->get('/admin/usuarios')->assertForbidden();
        $this->actingAs($driverUser)->get('/admin/reparaciones')->assertOk();
        $this->actingAs($driverUser)->get('/admin/reparaciones/create')->assertOk();

        $this->actingAs($supervisor)->get('/admin/unidades')->assertOk();
        $this->actingAs($supervisor)->get('/admin/conductores')->assertOk();
        $this->actingAs($supervisor)->get('/admin/usuarios')->assertForbidden();

        $this->actingAs($admin)->get('/admin/usuarios')->assertOk();
    }

    public function test_drivers_only_see_their_own_requests(): void
    {
        [$userA, $driverA] = $this->driverUser();
        [$userB] = $this->driverUser();
        $supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);

        $own = RepairRequest::factory()->create(['driver_id' => $driverA->getKey()]);
        $reportedByA = RepairRequest::factory()->create(['reported_by' => $userA->getKey()]);
        $foreign = RepairRequest::factory()->create();

        $this->assertEqualsCanonicalizing(
            [$own->getKey(), $reportedByA->getKey()],
            RepairRequest::query()->visibleTo($userA)->pluck('id')->all(),
        );
        $this->assertSame([], RepairRequest::query()->visibleTo($userB)->pluck('id')->all());
        $this->assertCount(3, RepairRequest::query()->visibleTo($supervisor)->get());

        $this->actingAs($userA)->get("/admin/reparaciones/{$own->getKey()}")->assertOk();
        $this->actingAs($userA)->get("/admin/reparaciones/{$foreign->getKey()}")->assertNotFound();
    }

    public function test_print_view_is_only_for_users_who_can_see_the_request(): void
    {
        [$owner, $driver] = $this->driverUser();
        [$stranger] = $this->driverUser();
        $request = RepairRequest::factory()->create(['driver_id' => $driver->getKey()]);

        $this->actingAs($owner)->get(route('repair-requests.print', $request))
            ->assertOk()
            ->assertSee($request->formattedNumber())
            ->assertSee($driver->name);

        $this->actingAs($stranger)->get(route('repair-requests.print', $request))->assertForbidden();
    }

    public function test_print_view_hides_costs_from_drivers(): void
    {
        [$owner, $driver] = $this->driverUser();
        $supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);
        $request = RepairRequest::factory()->create(['driver_id' => $driver->getKey()]);
        app(RepairWorkflow::class)->submitAssessment($supervisor, $request, ['urgency' => 'alta', 'estimated_cost' => 123456]);

        $this->actingAs($supervisor)->get(route('repair-requests.print', $request))->assertSee('123.456,00');
        $this->actingAs($owner)->get(route('repair-requests.print', $request))->assertDontSee('123.456,00');
    }

    public function test_signed_form_download_is_authorized(): void
    {
        Storage::fake('local');
        $path = RepairWorkflow::SIGNED_FORMS_DIRECTORY.'/aviso.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        [$owner, $driver] = $this->driverUser();
        [$stranger] = $this->driverUser();
        $request = RepairRequest::factory()->create(['driver_id' => $driver->getKey()]);
        $request->forceFill(['signed_form_path' => $path])->save();

        $this->actingAs($owner)->get(route('repair-requests.signed-form', $request))
            ->assertOk()
            ->assertDownload("aviso-{$request->formattedNumber()}-firmado.pdf");
        $this->actingAs($stranger)->get(route('repair-requests.signed-form', $request))->assertForbidden();
    }

    public function test_signed_form_download_404_when_missing(): void
    {
        $supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);
        $request = RepairRequest::factory()->create();

        // No file attached at all: policy denies.
        $this->actingAs($supervisor)->get(route('repair-requests.signed-form', $request))->assertForbidden();
    }

    public function test_units_and_drivers_with_history_cannot_be_deleted(): void
    {
        $supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);
        $request = RepairRequest::factory()->create();
        $freeDriver = Driver::factory()->create();

        $this->assertFalse($supervisor->can('delete', $request->unit));
        $this->assertFalse($supervisor->can('delete', $request->driver));
        $this->assertTrue($supervisor->can('delete', $freeDriver));
    }

    public function test_users_are_never_deleted(): void
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->assertFalse($admin->can('delete', User::factory()->create()));
    }
}
