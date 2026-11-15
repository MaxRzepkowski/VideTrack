<?php

namespace App\Support;

/**
 * Reads one value whose type PHP cannot know: an aggregate or a column a
 * query answers (`max('id')`, `sum('minutes')`, `pluck('id')`), which the
 * database driver hands over as a number or as digits. `Payload` reads the
 * same rules out of a decoded answer by key.
 */
final class Numbers
{
    /**
     * A whole number: an int, a float with nothing after the point, or
     * digits sent as text. Null for anything else, a fraction included.
     */
    public static function int(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) => is_finite($value) && floor($value) === $value ? (int) $value : null,
            is_string($value) => preg_match('/^\s*-?\d+\s*$/', $value) === 1 ? (int) $value : null,
            default => null,
        };
    }

    /** A number with or without a fraction, also when sent as text. */
    public static function float(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value) ? (float) $value : null;
    }

    /**
     * The whole numbers of a column (`pluck('id')`), in their order; null and
     * anything else left out.
     *
     * @param  iterable<mixed>  $values
     * @return list<int>
     */
    public static function ints(iterable $values): array
    {
        $ints = [];

        foreach ($values as $value) {
            $int = self::int($value);

            if ($int !== null) {
                $ints[] = $int;
            }
        }

        return $ints;
    }

    /**
     * A column keyed by another (`pluck('rating', 'media_item_id')`) as whole
     * numbers by whole numbers; an entry of any other shape left out.
     *
     * @param  iterable<mixed, mixed>  $values
     * @return array<int, int>
     */
    public static function intMap(iterable $values): array
    {
        $map = [];

        foreach ($values as $key => $value) {
            $key = self::int($key);
            $value = self::int($value);

            if ($key !== null && $value !== null) {
                $map[$key] = $value;
            }
        }

        return $map;
    }
}
