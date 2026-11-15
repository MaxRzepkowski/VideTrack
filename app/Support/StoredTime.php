<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A moment told in the zone the database keeps time in. The database binds a
 * moment by its wall clock, not its instant, so one told in a viewer's zone
 * would be compared, or written, hours off.
 */
final class StoredTime
{
    public static function of(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(config()->string('app.timezone'));
    }
}
