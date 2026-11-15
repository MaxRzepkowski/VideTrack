<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\GenreOption;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;

/**
 * Capability: the provider can serve the /discover sections — popular,
 * upcoming and by-genre listings per media type. A type-aware
 * counterpart of SupportsTrending: every method takes one of the codes
 * returned by discoverableTypes().
 */
interface SupportsDiscovery
{
    /**
     * Media type codes this provider can build discover listings for.
     *
     * @return list<string>
     */
    public function discoverableTypes(): array;

    /**
     * @return list<MediaSummary>
     */
    public function popular(string $type, string $locale): array;

    /**
     * Upcoming releases: theatrical premieres for movies, currently /
     * soon airing for shows.
     *
     * @return list<MediaSummary>
     */
    public function upcoming(string $type, string $locale): array;

    /**
     * Genres available for a type, in the order to show them.
     *
     * @return list<GenreOption>
     */
    public function genres(string $type, string $locale): array;

    /**
     * Popular titles of one genre, one page at a time (genre pages are the
     * only paginated discover listing).
     *
     * @return Paginated<MediaSummary>
     */
    public function byGenre(string $type, string $genreRef, string $locale, int $page = 1): Paginated;
}
