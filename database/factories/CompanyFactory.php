<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A studio by default; `network()` for a broadcaster.
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'tmdb',
            'kind' => 'company',
            'provider_ref' => (string) fake()->unique()->numberBetween(1, 9_999_999),
            'name' => fake()->company(),
        ];
    }

    public function network(): static
    {
        return $this->state(['kind' => 'network']);
    }
}
