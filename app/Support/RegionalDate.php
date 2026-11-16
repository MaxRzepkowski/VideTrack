<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A date an exporter writes the way the user's computer shows it, in the
 * format of their region ("11/16/2026 3:04:05 PM", "16.11.2026 15:04:05").
 */
final class RegionalDate
{
    /**
     * The day a date written the way a user's region writes it names: year
     * first ("2026-11-16"), or day and month in either order. A part above
     * 12 can only be the day; otherwise a 12-hour clock ("3:04:05 PM") is
     * the month-first American way, and a dot or a 24-hour clock the
     * day-first way of most other regions. Null when the value is no such
     * date. For an exporter that writes dates as the user's computer shows
     * them.
     */
    public static function day(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('#^\d{4}#', $value) === 1) {
            return Payload::calendarDay($value);
        }

        if (preg_match('#^(\d{1,2})([-./])(\d{1,2})\2(\d{4})#', $value, $parts) !== 1) {
            return null;
        }

        [$first, $second] = [(int) $parts[1], (int) $parts[3]];
        $monthFirst = match (true) {
            $first > 12 => false,
            $second > 12 => true,
            default => $parts[2] !== '.' && preg_match('/\b[AP]\.?M\.?$/i', $value) === 1,
        };

        return $monthFirst
            ? Payload::validDay((int) $parts[4], $first, $second)
            : Payload::validDay((int) $parts[4], $second, $first);
    }
}
