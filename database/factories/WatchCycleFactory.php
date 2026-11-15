<?php

namespace Database\Factories;

use App\Models\MediaItem;
use App\Models\User;
use App\Models\WatchCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The first explicit cycle is number 2: cycle one is implicit.
 *
 * @extends Factory<WatchCycle>
 */
class WatchCycleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'media_item_id' => MediaItem::factory()->show(),
            'number' => 2,
            'started_at' => now(),
        ];
    }
}
