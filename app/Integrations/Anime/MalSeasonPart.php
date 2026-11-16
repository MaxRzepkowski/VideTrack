<?php

namespace App\Integrations\Anime;

/**
 * One MyAnimeList entry inside a season of a TVDB series: it starts after
 * episode $offset and runs to where the next part of the season starts, or
 * to the season's end.
 */
final readonly class MalSeasonPart
{
    public function __construct(
        public int $malId,
        public int $offset,
    ) {}
}
