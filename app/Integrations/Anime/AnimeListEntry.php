<?php

namespace App\Integrations\Anime;

use Carbon\CarbonImmutable;

/**
 * One entry of an anime or manga list, the same whichever tracker it came
 * from: every one of them names titles by their MyAnimeList id (AniList as
 * idMal, Kitsu through its mappings). $progress is episodes watched or
 * chapters read; $total is the title's own count when the tracker knows it.
 */
final readonly class AnimeListEntry
{
    public function __construct(
        public ListMedium $medium,
        public int $malId,
        public string $title,
        public ListStatus $status,
        public ?int $score = null,
        public int $progress = 0,
        public ?int $total = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $finishedAt = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}

    public function isAnime(): bool
    {
        return $this->medium === ListMedium::Anime;
    }
}
