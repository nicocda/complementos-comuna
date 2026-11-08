<?php

namespace Database\Factories;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'internal_number' => fake()->unique()->numberBetween(1, 9999),
            'type' => fake()->randomElement([UnitType::Tractor, UnitType::Truck, UnitType::Pickup]),
            'name' => fake()->randomElement(['Massey Ferguson 1175', 'Iveco 170E22', 'Ford F100', 'John Deere 5065E']),
            'license_plate' => null,
            'status' => UnitStatus::Active,
        ];
    }

    public function implement(): static
    {
        return $this->state(fn () => [
            'type' => UnitType::Trailer,
            'name' => 'Carro playo volcador',
        ]);
    }

    public function outOfService(): static
    {
        return $this->state(fn () => ['status' => UnitStatus::OutOfService]);
    }
}
