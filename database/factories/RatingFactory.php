<?php

namespace Database\Factories;

use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rating>
 */
class RatingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'media_item_id' => MediaItem::factory()->movie(),
            'rating' => fake()->numberBetween(1, 10),
        ];
    }
}
