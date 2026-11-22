<?php

namespace App\Catalog\Wikidata;

use App\Support\Payload;

/**
 * Reads one row of a SPARQL answer, where each variable is bound as
 * `{"type": …, "value": …}` and an unbound one is missing.
 */
final readonly class SparqlRow
{
    /**
     * @param  array<array-key, mixed>  $row
     */
    public static function value(array $row, string $variable): ?string
    {
        $binding = $row[$variable] ?? null;

        return is_array($binding) ? Payload::string($binding, 'value') : null;
    }

    /**
     * The QID a variable names, read off the end of its entity URI.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function entity(array $row, string $variable): ?string
    {
        $uri = self::value($row, $variable);

        return $uri === null ? null : basename($uri);
    }
}
