<?php

namespace Database\Factories;

use App\Enums\ProblemKind;
use App\Enums\RepairStatus;
use App\Models\Driver;
use App\Models\RepairRequest;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Only for tests and demo data; real requests are created through RepairWorkflow.
 *
 * @extends Factory<RepairRequest>
 */
class RepairRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => fn () => ((int) RepairRequest::query()->max('number')) + 1,
            'status' => RepairStatus::Reported,
            'unit_id' => Unit::factory(),
            'unit_label' => fn (array $attributes) => Unit::query()->find($attributes['unit_id'])?->label() ?? 'Unidad',
            'driver_id' => Driver::factory(),
            'reported_by' => User::factory(),
            'problem_kind' => ProblemKind::Breakdown,
            'problem_description' => fake()->sentence(10),
            'detected_on' => today(),
        ];
    }

    public function status(RepairStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
