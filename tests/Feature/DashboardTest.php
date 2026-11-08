<?php

namespace Tests\Feature;

use App\Enums\RepairStatus;
use App\Filament\Widgets\RepairOverview;
use App\Models\RepairRequest;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_supervisor_sees_counts_and_yearly_cost(): void
    {
        RepairRequest::factory()->create();
        RepairRequest::factory()->status(RepairStatus::PendingApproval)->create();
        RepairRequest::factory()->status(RepairStatus::Completed)->create(['final_cost' => 1500, 'completed_on' => today()]);

        $this->actingAs($this->userWithRole(Permissions::ROLE_SUPERVISOR));

        Livewire::test(RepairOverview::class)
            ->assertSee('Esperando informe')
            ->assertSee('Pendientes de aprobación')
            ->assertSee('Costo de reparaciones');

        $this->get('/admin')->assertOk()->assertSee('Reparaciones');
    }

    public function test_driver_does_not_see_costs(): void
    {
        [$user, $driver] = $this->driverUser();
        RepairRequest::factory()->create(['driver_id' => $driver->getKey()]);

        $this->actingAs($user);

        Livewire::test(RepairOverview::class)
            ->assertSee('Esperando informe')
            ->assertDontSee('Costo de reparaciones');
    }
}
