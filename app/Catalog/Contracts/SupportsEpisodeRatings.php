<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\SeasonRatings;

/**
 * Capability: the provider can serve per-episode community ratings of a whole
 * show in bulk (for the episode-quality chart) — without one request per
 * season page.
 */
interface SupportsEpisodeRatings
{
    /**
     * Seasons in ascending order (specials excluded), each with every known
     * episode.
     *
     * @return list<SeasonRatings>
     */
    public function episodeRatings(string $ref, string $locale): array;
}
