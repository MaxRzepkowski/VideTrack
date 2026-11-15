<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'tmdb',
            'provider_ref' => (string) fake()->unique()->numberBetween(1, 9_999_999),
            'name' => fake()->name(),
        ];
    }
}
