<?php

namespace App\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;

/**
 * Reads values out of data decoded from another service (an API answer, an
 * export file, a webhook body), where any field can be missing, blank or of
 * the wrong type. Each reader answers null (or an empty list) for all of
 * those, so callers handle "absent" once and never cast.
 */
final class Payload
{
    /**
     * A non-blank string, trimmed.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A string the shape requires (a title, a name), trimmed; empty when the
     * service left it out, so the card still renders.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function text(array $data, string $key): string
    {
        return self::string($data, $key) ?? '';
    }

    /**
     * A whole number, zero and below included (a season number, an offset):
     * an int, a float with nothing after the point, or digits sent as text.
     * Null for anything else, a fraction included.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key): ?int
    {
        return Numbers::int($data[$key] ?? null);
    }

    /**
     * A number with or without a fraction (a rating, a share), also when sent
     * as text. Null for anything else.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function float(array $data, string $key): ?float
    {
        return Numbers::float($data[$key] ?? null);
    }

    /**
     * A true or false the service sent as such; null for a missing key and
     * for anything else, so "not said" never reads as "no".
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * The objects of a list (results, cast, seasons), each as an array, in
     * order; an object's values read the same way. An entry of another shape
     * is dropped, a value that is neither reads as none.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * The objects of a list, each read by `$read`; one it cannot read (it
     * gives null) is dropped, the rest keep their order.
     *
     * @template T of object
     *
     * @param  Closure(array<array-key, mixed>): (T|null)  $read
     * @return list<T>
     */
    public static function listOf(mixed $value, Closure $read): array
    {
        return array_values(array_filter(array_map($read, self::list($value))));
    }

    /**
     * The objects of a list that name what they stand for by an `id` (an
     * `id()`): a row without one links nowhere, so it is left out.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(mixed $value): array
    {
        return array_values(array_filter(self::list($value), fn (array $row): bool => self::id($row['id'] ?? null) !== null));
    }

    /**
     * An object (a nested block such as `external_ids`), empty when the value
     * is missing or of another shape.
     *
     * @return array<array-key, mixed>
     */
    public static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * An object by the names of its entries (a stored settings bag, a form's
     * fields), empty when the value is of another shape. PHP turns a name
     * made only of digits into a number, so such an entry is the one dropped.
     *
     * @return array<string, mixed>
     */
    public static function named(mixed $value): array
    {
        return is_array($value) ? array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * A count or an id above zero; null for anything else — providers send 0
     * or nothing for "unknown".
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function positiveInt(array $data, string $key): ?int
    {
        $value = self::int($data, $key);

        return $value !== null && $value > 0 ? $value : null;
    }

    /**
     * One of a string-backed enum's values; null for a value the enum does
     * not know, which is how a closed set ignores what a service adds later.
     *
     * @template T of BackedEnum
     *
     * @param  array<array-key, mixed>  $data
     * @param  class-string<T>  $enum
     * @return T|null
     */
    public static function enum(array $data, string $key, string $enum): ?BackedEnum
    {
        $value = self::string($data, $key);

        return $value === null ? null : $enum::tryFrom($value);
    }

    /**
     * The same for an int-backed enum: a service's numeric code.
     *
     * @template T of BackedEnum
     *
     * @param  array<array-key, mixed>  $data
     * @param  class-string<T>  $enum
     * @return T|null
     */
    public static function intEnum(array $data, string $key, string $enum): ?BackedEnum
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $enum::tryFrom($value) : null;
    }

    /**
     * The `name` of every entry that has one — the shape both TMDB and IGDB
     * use for genres, platforms, keywords and the like.
     *
     * @return list<string>
     */
    public static function names(mixed $entries): array
    {
        $names = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $name = is_array($entry) ? self::string($entry, 'name') : null;

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * A moment in any format Carbon reads. Blank is absent rather than "now",
     * which is what Carbon would make of an empty string.
     */
    public static function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // A calendar day with no hour names no moment of it.
        if (preg_match('#^(\d{4})[-/](\d{2})[-/](\d{2})$#', $value, $parts) === 1) {
            return self::validDay((int) $parts[1], (int) $parts[2], (int) $parts[3]);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * A moment sent as milliseconds since the epoch, the way JavaScript
     * writes a date (`Date.valueOf()`). Zero and below are "never", not 1970.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function epochMilliseconds(array $data, string $key): ?CarbonImmutable
    {
        $milliseconds = self::positiveInt($data, $key);

        return $milliseconds === null ? null : CarbonImmutable::createFromTimestampMsUTC($milliseconds);
    }

    /**
     * A yes or no a file writes as text: "true" and "yes" in any case are
     * yes (.NET writes "True", other exporters "yes"); anything else no.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function flag(array $data, string $key): bool
    {
        return in_array(mb_strtolower(self::text($data, $key)), ['true', 'yes'], true);
    }

    /**
     * The calendar day a value opens with, written year first ("2024-04-25",
     * "2024/4/25", "2024-04-25T06:54:47Z"), as {@see day()} reads it. Null
     * for anything else, a year or a month alone and a placeholder ("None")
     * included: unlike {@see instant()} it never guesses at a value.
     */
    public static function calendarDay(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('#^\s*(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?!\d)#', $value, $parts) !== 1) {
            return null;
        }

        return self::validDay((int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    /** {@see day()} for numbers read from a file, which may name no day of the calendar (2023-02-30). */
    public static function validDay(int $year, int $month, int $day): ?CarbonImmutable
    {
        return checkdate($month, $day, $year) ? self::day($year, $month, $day) : null;
    }

    /**
     * A calendar day from a source that dates by day alone, as a moment:
     * noon UTC, which every timezone from UTC−11 to UTC+11 reads as that
     * same day. Midnight UTC would put it on the day before for anyone west
     * of Greenwich.
     */
    public static function day(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::createMidnightDate($year, $month, $day, 'UTC')->setTime(12, 0);
    }

    /**
     * An identifier as a string: services send the same id as a number in
     * one answer and as a string in the next. Null for anything else.
     */
    public static function id(mixed $value): ?string
    {
        return is_int($value) || (is_string($value) && $value !== '') ? (string) $value : null;
    }

    /**
     * A list of identifiers (`[1, "2"]`), each as a string, in order; an entry
     * of another shape dropped.
     *
     * @return list<string>
     */
    public static function idList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map(self::id(...), $value), fn (?string $id): bool => $id !== null)) : [];
    }

    /**
     * A bag of identifiers by catalogue (`{"imdb": "tt…", "tmdb": 603}`), each
     * as a string; entries of another shape dropped.
     *
     * @return array<string, string>
     */
    public static function ids(mixed $bag): array
    {
        $ids = [];

        foreach (is_array($bag) ? $bag : [] as $source => $value) {
            $id = self::id($value);

            if (is_string($source) && $id !== null) {
                $ids[$source] = $id;
            }
        }

        return $ids;
    }

    /**
     * The strings of a list, the rest dropped.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * The strings of a list that say something, trimmed (a service's list of
     * names may carry blanks); the rest dropped.
     *
     * @return list<string>
     */
    public static function texts(mixed $value): array
    {
        $texts = [];

        foreach (self::strings($value) as $text) {
            $text = trim($text);

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * A map of string keys to string values (a label and its address), the
     * entries of any other shape dropped.
     *
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, string> */
        return array_filter($value, fn (mixed $entry, mixed $key): bool => is_string($key) && is_string($entry), ARRAY_FILTER_USE_BOTH);
    }

    /** The year a date (`2026-10-10`, `2026`) opens with; null for none. */
    public static function yearOf(?string $date): ?int
    {
        return $date !== null && preg_match('/^\d{4}/', $date, $year) === 1 ? (int) $year[0] : null;
    }
}
