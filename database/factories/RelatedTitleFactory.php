<?php

namespace Database\Factories;

use App\Catalog\Relations\TitleRelation;
use App\Models\MediaItem;
use App\Models\RelatedTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedTitle>
 */
class RelatedTitleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_item_id' => MediaItem::factory(),
            'relation' => TitleRelation::SpinOff,
            'type' => 'show',
            'provider' => 'tmdb',
            'ref' => (string) fake()->unique()->numberBetween(1, 9_999_999),
            'title' => fake()->words(2, true),
            'release_date' => '2015-01-01',
            'card' => ['year' => 2015],
        ];
    }
}
