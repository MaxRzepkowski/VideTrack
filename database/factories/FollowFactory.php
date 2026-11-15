<?php

namespace Database\Factories;

use App\Enums\FollowMode;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A follow of a title by default; give it another target with
 * `['media_item_id' => null, 'person_id' => …]`.
 *
 * @extends Factory<Follow>
 */
class FollowFactory extends Factory
{
    private const OTHER_TARGETS = ['person_id', 'company_id', 'list_id'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // A bell on a person, a company or a collection has no title.
            'media_item_id' => fn (array $attributes) => array_intersect_key($attributes, array_flip(self::OTHER_TARGETS)) === []
                ? MediaItem::factory()->movie()
                : null,
            'mode' => FollowMode::Follow,
        ];
    }

    public function muted(): static
    {
        return $this->state(['mode' => FollowMode::Mute]);
    }
}
