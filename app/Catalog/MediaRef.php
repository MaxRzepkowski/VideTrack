<?php

namespace App\Catalog;

/**
 * The shape of a position's ref, whoever addresses it: a route, a request
 * field, an import row.
 *
 * Input hygiene for opaque provider refs — deliberately provider-agnostic (the
 * core never assumes a numeric id scheme). Refs are interpolated into provider
 * URLs and cache keys, so only what lets a crafted value escape a single path
 * segment is blocked: slashes, whitespace, control and scheme characters. Any
 * sane id shape (numbers, `show:season:episode`, slugs, UUIDs, `OL27448W`)
 * passes; an unknown ref just fails at the provider with ItemNotFoundException.
 */
final class MediaRef
{
    /** Unanchored, for route constraints. Colons are legal in a path (RFC 3986). */
    public const PATTERN = '[A-Za-z0-9][A-Za-z0-9._:-]*';

    /** The same shape as a validation rule. */
    public const FORMAT = 'regex:/^'.self::PATTERN.'$/';

    public const MAX_LENGTH = 100;

    /** Whether a value from outside has the shape to be interpolated as a ref. */
    public static function holds(string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH && preg_match('/^'.self::PATTERN.'$/', $value) === 1;
    }
}
