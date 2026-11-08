<?php

namespace Tests\Feature;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Units\Pages\CreateUnit;
use App\Filament\Resources\Units\Pages\EditUnit;
use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\Driver;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FleetResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->actingAs($this->userWithRole(Permissions::ROLE_SUPERVISOR));
    }

    public function test_creates_an_implement_attached_to_a_unit(): void
    {
        $tractor = Unit::factory()->create(['internal_number' => 1, 'type' => UnitType::Tractor]);

        Livewire::test(CreateUnit::class)
            ->fillForm([
                'internal_number' => 44,
                'type' => UnitType::Trailer->value,
                'name' => 'Carro playo volcador',
                'license_plate' => ' ab123cd ',
                'status' => UnitStatus::Active->value,
                'attached_to_unit_id' => $tractor->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $trailer = Unit::query()->where('internal_number', 44)->sole();
        $this->assertTrue($trailer->attachedTo->is($tractor));
        $this->assertSame('AB123CD', $trailer->license_plate);
    }

    public function test_internal_number_must_be_unique(): void
    {
        Unit::factory()->create(['internal_number' => 7]);

        Livewire::test(CreateUnit::class)
            ->fillForm(['internal_number' => 7, 'type' => UnitType::Truck->value, 'name' => 'Otro', 'status' => UnitStatus::Active->value])
            ->call('create')
            ->assertHasFormErrors(['internal_number' => 'unique']);
    }

    public function test_edit_unit_page_renders_with_relations(): void
    {
        $unit = Unit::factory()->create();
        Unit::factory()->implement()->create(['attached_to_unit_id' => $unit->getKey()]);
        Driver::factory()->create()->units()->attach($unit);

        Livewire::test(EditUnit::class, ['record' => $unit->getKey()])
            ->assertOk()
            ->fillForm(['status' => UnitStatus::InWorkshop->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(UnitStatus::InWorkshop, $unit->fresh()->status);
        Livewire::test(ListUnits::class)->assertCanSeeTableRecords([$unit]);
    }

    public function test_creates_driver_linked_to_user_and_units(): void
    {
        $units = Unit::factory()->count(2)->create();
        $user = User::factory()->create();

        Livewire::test(CreateDriver::class)
            ->fillForm([
                'name' => 'Conductor Nuevo',
                'user_id' => $user->getKey(),
                'is_active' => true,
                'units' => $units->modelKeys(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $driver = Driver::query()->where('name', 'Conductor Nuevo')->sole();
        $this->assertTrue($driver->user->is($user));
        $this->assertEqualsCanonicalizing($units->modelKeys(), $driver->units->modelKeys());
    }

    public function test_user_cannot_be_linked_to_two_drivers(): void
    {
        $user = User::factory()->create();
        Driver::factory()->create(['user_id' => $user->getKey()]);
        $other = Driver::factory()->create();

        Livewire::test(EditDriver::class, ['record' => $other->getKey()])
            ->fillForm(['user_id' => $user->getKey()])
            ->call('save')
            ->assertHasFormErrors(['user_id']);
    }
}
