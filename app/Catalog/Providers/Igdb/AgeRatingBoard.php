<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\ReleaseRegion;

/**
 * Which of a game's age ratings a viewer reads: the board of their country
 * (a German player wants USK, a Polish one PEGI), falling back through the
 * boards that rate most games. Prefixed with the board, so a lone "E" or "12"
 * reads unambiguously.
 */
final class AgeRatingBoard
{
    /** Countries with a board of their own. */
    private const BY_COUNTRY = [
        'DE' => 'USK',
        'JP' => 'CERO',
        'KR' => 'GRAC',
        'BR' => 'ClassInd',
        'AU' => 'ACB',
        'NZ' => 'ACB',
    ];

    private const FALLBACK = ['PEGI', 'ESRB'];

    /**
     * @param  array<string, string>  $ratings  board => category (PEGI => 12)
     */
    public static function chip(array $ratings, string $country): ?string
    {
        $country = strtoupper($country);
        $own = self::BY_COUNTRY[$country] ?? match (ReleaseRegion::ofCountry($country)) {
            ReleaseRegion::Europe => 'PEGI',
            ReleaseRegion::NorthAmerica => 'ESRB',
            default => null,
        };

        foreach ([$own, ...self::FALLBACK, ...array_keys($ratings)] as $board) {
            if ($board !== null && isset($ratings[$board])) {
                return "{$board} {$ratings[$board]}";
            }
        }

        return null;
    }
}
