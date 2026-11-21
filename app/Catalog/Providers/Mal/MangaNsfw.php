<?php

namespace App\Catalog\Providers\Mal;

use App\Catalog\MatureContent;

/**
 * How safe for work MAL rates a manga: `white` is, `gray` may not be
 * (suggestive, ecchi), `black` is not.
 */
enum MangaNsfw: string
{
    case White = 'white';
    case Gray = 'gray';
    case Black = 'black';

    /** Adult material, as a film filed `adult` at TMDB: kept from beside other titles whatever the viewer allows. */
    public function isAdult(): bool
    {
        return $this === self::Black;
    }

    public function allowedAt(MatureContent $mature): bool
    {
        return match ($this) {
            self::White => true,
            self::Gray => $mature->allowsSuggestive(),
            self::Black => $mature->allowsAdult(),
        };
    }
}
