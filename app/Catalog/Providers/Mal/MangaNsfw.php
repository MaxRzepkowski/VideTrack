<?php

namespace App\Catalog\Providers\Mal;

/**
 * How safe for work MAL rates a manga: `white` is, `gray` may not be
 * (suggestive, ecchi), `black` is not.
 */
enum MangaNsfw: string
{
    case White = 'white';
    case Gray = 'gray';
    case Black = 'black';

    /** Adult material, as a film filed `adult` at TMDB: kept from every listing and from beside other titles. */
    public function isAdult(): bool
    {
        return $this === self::Black;
    }
}
