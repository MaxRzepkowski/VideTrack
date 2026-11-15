<?php

namespace Database\Factories;

use App\Models\CatalogSet;
use App\Models\CatalogSetTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogSetTitle>
 */
class CatalogSetTitleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_set_id' => CatalogSet::factory(),
            'position' => fake()->unique()->numberBetween(1, 999999),
            'type' => 'movie',
            'provider' => 'tmdb',
            'ref' => (string) fake()->unique()->numberBetween(1, 999999),
            'release_date' => '2020-01-01',
            'title' => fake()->words(2, true),
            'card' => ['year' => 2020, 'posterPath' => '/poster.jpg'],
        ];
    }
}
