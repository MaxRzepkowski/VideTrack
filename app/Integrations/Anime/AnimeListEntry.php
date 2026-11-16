<?php

namespace App\Integrations\Anime;

use App\Integrations\Export\ListStatus;
use App\Library\RatingScale;
use Carbon\CarbonImmutable;

/**
 * One entry of an anime or manga list, the same whichever tracker it came
 * from: every one of them names titles by their MyAnimeList id (AniList as
 * idMal, Kitsu through its mappings). $progress is episodes watched or
 * chapters read; $total is the title's own count when the tracker knows it.
 * A tracker that names no title leaves the entry known by its id, and a
 * score past our scale is our scale's top: every tracker scores 1–10 or finer.
 */
final readonly class AnimeListEntry
{
    public string $title;

    public ?int $score;

    public function __construct(
        public ListMedium $medium,
        public int $malId,
        ?string $title,
        public ListStatus $status,
        ?int $score = null,
        public int $progress = 0,
        public ?int $total = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $finishedAt = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {
        $this->title = $title ?? "MAL {$malId}";
        $this->score = $score === null ? null : min(RatingScale::MAX, $score);
    }

    /** How a report names an entry: by its title and its MyAnimeList id. */
    public static function label(string $title, int $malId): string
    {
        return "{$title} (MAL {$malId})";
    }

    public function isAnime(): bool
    {
        return $this->medium === ListMedium::Anime;
    }
}
