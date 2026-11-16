<?php

namespace App\Catalog;

/**
 * An IMDb title id in the one spelling the catalog asks about: "tt" and
 * the digits. Exports write it bare ("tt0092794"), without the prefix
 * ("92794"), or as the title's page ("https://www.imdb.com/title/tt0092794/").
 */
final class ImdbId
{
    /** The id however it is written; null when the value holds none. */
    public static function read(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('#^(?:https?://(?:[a-z]+\.)?imdb\.com/title/)?(tt\d+)/?$#i', $value, $match) === 1) {
            return strtolower($match[1]);
        }

        return ctype_digit($value) && (int) $value > 0 ? "tt{$value}" : null;
    }
}
