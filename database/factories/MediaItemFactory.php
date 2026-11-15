<?php

namespace Database\Factories;

use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds media hierarchies without HTTP — the backbone of the cascade and
 * resolver tests. Season/episode refs follow the TMDB colon encoding so
 * factory data matches what the provider produces.
 *
 * @extends Factory<MediaItem>
 */
class MediaItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'movie',
            'provider' => 'tmdb',
            'provider_ref' => (string) fake()->unique()->numberBetween(1, 9_999_999),
            'title' => fake()->sentence(3),
            'year' => fake()->numberBetween(1970, 2026),
            'release_date' => fake()->dateTimeBetween('-30 years', '-1 month')->format('Y-m-d'),
            'runtime' => fake()->numberBetween(20, 180),
        ];
    }

    public function movie(): static
    {
        return $this->state(['type' => 'movie']);
    }

    public function show(): static
    {
        return $this->state(['type' => 'show', 'runtime' => null]);
    }

    public function game(): static
    {
        return $this->state(['type' => 'game', 'provider' => 'igdb']);
    }

    public function book(): static
    {
        return $this->state([
            'type' => 'book',
            'provider' => 'hardcover',
            'runtime' => null,
            // The page count caps progress inputs and drives progress bars.
            'metadata' => ['pages' => 320],
        ]);
    }

    public function manga(): static
    {
        return $this->state([
            'type' => 'manga',
            'provider' => 'mal',
            'runtime' => null,
            // The chapter count caps progress inputs and drives progress bars.
            'metadata' => ['chapters' => 162],
        ]);
    }

    public function comic(): static
    {
        return $this->state([
            'type' => 'comic',
            'provider' => 'comicvine',
            'runtime' => null,
            // ComicVine serves no release dates on volumes.
            'release_date' => null,
            // The issue count caps progress inputs and drives progress bars.
            'metadata' => ['issues' => 75],
        ]);
    }

    public function season(MediaItem $show, int $number): static
    {
        return $this->state([
            'type' => 'season',
            'provider_ref' => "{$show->provider_ref}:{$number}",
            'parent_id' => $show->id,
            'number' => $number,
            'runtime' => null,
        ]);
    }

    public function episode(MediaItem $season, int $number, ?string $airDate = '2020-01-01'): static
    {
        return $this->state([
            'type' => 'episode',
            'provider_ref' => "{$season->provider_ref}:{$number}",
            'parent_id' => $season->id,
            'number' => $number,
            'release_date' => $airDate,
        ]);
    }
}
