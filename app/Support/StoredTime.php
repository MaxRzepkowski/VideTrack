<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The clock the database keeps time on: the instance's zone, which Laravel
 * makes PHP's default and Eloquent reads every stored time in. The database
 * binds a moment by its wall clock, not its instant, so one told in another
 * zone (UTC from a service, a viewer's own) would be written, or compared,
 * hours off.
 */
final class StoredTime
{
    /** The same instant, told on the stored clock. */
    public static function of(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(date_default_timezone_get());
    }

    /** A moment as a column holds it ("2026-10-10 14:00:00"). */
    public static function read(string $stored): CarbonImmutable
    {
        return CarbonImmutable::parse($stored, date_default_timezone_get());
    }
}
