<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Model;
use stdClass;
use UnexpectedValueException;

/**
 * Reads a column out of a raw row our own query answered (`toBase()`,
 * `DB::table()`, `selectRaw`), where PHP knows no column's type, or an alias
 * such a query put on a model (a joined `list_items.list_id`), which no cast
 * describes. Unlike `Payload`, nothing here is forgiving: the query asked for
 * the column, so a missing one, an unexpected NULL or a value of another type
 * is a bug in the query and stops at once instead of turning into a zero or
 * an empty string.
 * A whole number follows `Numbers::int`, so digits a driver sends as text
 * still read; text is returned as stored, untrimmed, because it is often a
 * key compared further on.
 */
final class QueryRow
{
    /** A whole number the row must carry: a NOT NULL column, `count(*)`. */
    public static function int(stdClass|Model $row, string $column): int
    {
        return self::intOrNull($row, $column) ?? throw self::unexpected($column);
    }

    /** A whole number that may be NULL: a nullable column, `max()` over an empty set. */
    public static function intOrNull(stdClass|Model $row, string $column): ?int
    {
        $value = self::value($row, $column);

        if ($value === null) {
            return null;
        }

        return Numbers::int($value) ?? throw self::unexpected($column);
    }

    /** Text the row must carry. */
    public static function text(stdClass|Model $row, string $column): string
    {
        return self::textOrNull($row, $column) ?? throw self::unexpected($column);
    }

    /** Text that may be NULL. */
    public static function textOrNull(stdClass|Model $row, string $column): ?string
    {
        $value = self::value($row, $column);

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw self::unexpected($column);
    }

    /** A moment the row must carry, read on the clock the database keeps time on. */
    public static function moment(stdClass|Model $row, string $column): CarbonImmutable
    {
        return self::momentOrNull($row, $column) ?? throw self::unexpected($column);
    }

    /** A moment that may be NULL: a nullable column, `max()` over an empty set. */
    public static function momentOrNull(stdClass|Model $row, string $column): ?CarbonImmutable
    {
        $text = self::textOrNull($row, $column);

        if ($text === null) {
            return null;
        }

        // Carbon reads blank as now; a column holds no such moment.
        if (trim($text) === '') {
            throw self::unexpected($column);
        }

        try {
            return StoredTime::read($text);
        } catch (InvalidFormatException) {
            throw self::unexpected($column);
        }
    }

    private static function value(stdClass|Model $row, string $column): mixed
    {
        if ($row instanceof Model) {
            // Raw attributes, not the model's getter: an alias has no cast, and
            // a relation of the same name must not answer for it.
            $attributes = $row->getAttributes();

            return array_key_exists($column, $attributes) ? $attributes[$column] : throw self::missing($column);
        }

        return property_exists($row, $column) ? $row->{$column} : throw self::missing($column);
    }

    private static function missing(string $column): UnexpectedValueException
    {
        return new UnexpectedValueException("The row has no column [{$column}].");
    }

    /** Names the column only: its value could be a secret. */
    private static function unexpected(string $column): UnexpectedValueException
    {
        return new UnexpectedValueException("The column [{$column}] does not hold the value the query promises.");
    }
}
