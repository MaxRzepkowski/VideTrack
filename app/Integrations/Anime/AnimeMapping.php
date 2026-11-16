<?php

namespace App\Integrations\Anime;

/**
 * Where one MyAnimeList entry sits in the TVDB/IMDb world, per the Kometa
 * anime id map: a season of a TVDB series (with the episode offset at which
 * the MAL entry starts), or — season 0 — a special or film, known by its own
 * IMDb id when it has exactly one.
 */
final readonly class AnimeMapping
{
    /**
     * @param  list<string>  $imdbIds
     */
    public function __construct(
        public ?int $tvdbId,
        public ?int $tvdbSeason,
        public int $episodeOffset,
        public array $imdbIds,
    ) {}

    /**
     * A regular season of a series — episodes can be ticked.
     *
     * @phpstan-assert-if-true int $this->tvdbId
     * @phpstan-assert-if-true int $this->tvdbSeason
     */
    public function isSeason(): bool
    {
        return $this->tvdbId !== null && $this->tvdbSeason !== null && $this->tvdbSeason > 0;
    }

    /** A film or special with an unambiguous id of its own. */
    public function standaloneImdb(): ?string
    {
        return count($this->imdbIds) === 1 ? $this->imdbIds[0] : null;
    }
}
