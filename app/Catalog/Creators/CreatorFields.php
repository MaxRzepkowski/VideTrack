<?php

namespace App\Catalog\Creators;

/**
 * How a creator row takes in what a provider says: a write never blanks a
 * column the provider left empty — a portrait-less credit must not wipe the
 * biography a page fetch stored earlier, a locale without a place of birth
 * must not erase the one stored from another.
 */
final class CreatorFields
{
    /**
     * The fields a write owns laid over what the row already holds, the
     * empty ones dropped.
     *
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>  $fresh
     * @return array<string, mixed>|null
     */
    public static function metadata(?array $stored, array $fresh): ?array
    {
        $fresh = array_filter($fresh, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        $merged = [...($stored ?? []), ...$fresh];

        return $merged === [] ? null : $merged;
    }

    /** Text worth storing: null for nothing but whitespace. */
    public static function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
