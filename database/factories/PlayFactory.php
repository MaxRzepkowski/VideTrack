<?php

namespace Database\Factories;

use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Play>
 */
class PlayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'media_item_id' => MediaItem::factory()->movie(),
            'watched_at' => now(),
            'outcome' => PlayOutcome::Completed,
        ];
    }

    public function dropped(): static
    {
        return $this->state(['outcome' => PlayOutcome::Dropped]);
    }
}
