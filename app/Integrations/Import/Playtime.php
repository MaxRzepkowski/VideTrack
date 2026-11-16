<?php

namespace App\Integrations\Import;

/**
 * Time spent on a title as an export writes it: a launcher's total for a
 * game, in the minutes its progress counts, or how long a playback ran.
 * Launchers keep one running total, never sessions, so the total is all a
 * file can give; in minutes a zero is "never launched", not a play of no
 * length, and reads as nothing.
 */
final class Playtime
{
    private const SECONDS_PER_MINUTE = 60;

    private const SECONDS_PER_HOUR = 3600;

    /** "8:35:30" (hours), "46:30" (minutes) or a bare count of seconds, in minutes; blanks and "--" are nothing. */
    public static function read(string $value): ?int
    {
        $seconds = self::seconds($value);

        return $seconds === null ? null : self::ofSeconds($seconds);
    }

    /** The same, in seconds. */
    public static function seconds(string $value): ?int
    {
        $parts = explode(':', trim($value));

        if (array_filter($parts, fn (string $part): bool => ! ctype_digit($part)) !== []) {
            return null;
        }

        return match (count($parts)) {
            3 => (int) $parts[0] * self::SECONDS_PER_HOUR + (int) $parts[1] * self::SECONDS_PER_MINUTE + (int) $parts[2],
            2 => (int) $parts[0] * self::SECONDS_PER_MINUTE + (int) $parts[1],
            1 => (int) $parts[0],
            default => null,
        };
    }

    /** A total in seconds, to the nearest minute. */
    public static function ofSeconds(int $seconds): ?int
    {
        $minutes = (int) round($seconds / self::SECONDS_PER_MINUTE);

        return $minutes > 0 ? $minutes : null;
    }

    /** A total a file already writes in minutes. */
    public static function minutes(string $value): ?int
    {
        $value = trim($value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
