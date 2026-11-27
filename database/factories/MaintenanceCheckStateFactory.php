<?php

namespace Database\Factories;

use App\Enums\TaskRunStatus;
use App\Models\MaintenanceCheckState;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A check that ran and found nothing to say; give it its code.
 *
 * @extends Factory<MaintenanceCheckState>
 */
class MaintenanceCheckStateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'status' => TaskRunStatus::Success,
            'started_at' => now(),
            'checked_at' => now(),
        ];
    }

    public function checkedAt(DateTimeInterface $at): static
    {
        return $this->state(['started_at' => $at, 'checked_at' => $at]);
    }

    public function started(DateTimeInterface $at): static
    {
        return $this->state(['status' => TaskRunStatus::Started, 'started_at' => $at, 'checked_at' => null]);
    }

    public function pending(): static
    {
        return $this->state(['status' => TaskRunStatus::Pending]);
    }
}
