<?php

namespace App\Support;

/**
 * A typed search term as a `like` pattern. A `%` or `_` the user typed is a
 * literal, not a wildcard: a search for "100%" must not match every title.
 * Paired with an explicit `escape` clause naming {@see ESCAPE}, because
 * neither SQLite nor Laravel assumes one.
 */
final class LikePattern
{
    public const ESCAPE = '\\';

    /** Text holding the term anywhere. */
    public static function containing(string $term): string
    {
        return '%'.self::literal($term).'%';
    }

    /** Text opening with the term. */
    public static function startingWith(string $term): string
    {
        return self::literal($term).'%';
    }

    private static function literal(string $term): string
    {
        return addcslashes($term, self::ESCAPE.'%_');
    }
}
