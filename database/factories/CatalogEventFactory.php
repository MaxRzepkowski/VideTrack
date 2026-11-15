<?php

namespace Database\Factories;

use App\Models\CatalogEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogEvent>
 */
class CatalogEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'igdb',
            'ref' => (string) fake()->unique()->numberBetween(1, 9_999_999),
            'name' => 'Summer Game Fest '.fake()->year(),
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
            'live_stream_url' => 'https://www.youtube.com/watch?v=sgf',
        ];
    }
}
