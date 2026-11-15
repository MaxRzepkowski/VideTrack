<?php

namespace Database\Factories;

use App\Catalog\Sets\SetKind;
use App\Models\CatalogSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogSet>
 */
class CatalogSetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => SetKind::Collection,
            'provider' => 'tmdb',
            'ref' => (string) fake()->unique()->numberBetween(1, 999999),
            'locale' => 'en',
            'name' => fake()->words(3, true),
            'wanted_at' => now(),
            'fetched_at' => now(),
        ];
    }

    /** Asked for, never listed by its provider yet. */
    public function unfetched(): static
    {
        return $this->state(['fetched_at' => null, 'name' => null]);
    }
}
