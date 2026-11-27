<?php

namespace Database\Factories;

use App\Models\MediaItem;
use App\Models\RecentView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A user's view of a title just now; give it the title with `of()`.
 *
 * @extends Factory<RecentView>
 */
class RecentViewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'movie',
            'provider_ref' => (string) fake()->unique()->numberBetween(1, 9_999_999),
            'title' => fake()->sentence(3),
            'viewed_at' => now(),
        ];
    }

    public function of(MediaItem $title): static
    {
        return $this->state(['type' => $title->type, 'provider_ref' => $title->provider_ref, 'title' => $title->title]);
    }
}
