<?php

namespace App\Catalog;

use Carbon\CarbonImmutable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How much of a release date the provider knows. A game announced for "2028"
 * still arrives as one day (IGDB stamps the year's last), so the date column
 * keeps sorting and windowing while this says the day itself is a stand-in:
 * the page writes "2028", and nothing counts down to, or lands on, that day.
 */
#[TypeScript]
enum DatePrecision: string
{
    case Day = 'day';

    case Month = 'month';

    case Quarter = 'quarter';

    case Year = 'year';

    /**
     * The latest day the date may mean: a release "in March" is still ahead on
     * the 20th, whichever day of March the provider stamped it with.
     */
    public function lastDay(string $date): string
    {
        return match ($this) {
            self::Day => $date,
            self::Month => CarbonImmutable::parse($date)->endOfMonth()->toDateString(),
            self::Quarter => CarbonImmutable::parse($date)->endOfQuarter()->toDateString(),
            self::Year => CarbonImmutable::parse($date)->endOfYear()->toDateString(),
        };
    }

    /** Whether a date known this well may still mean `$day` (Y-m-d) or later: a release "in March" is still to come on the 20th. */
    public function isStillAhead(string $date, string $day): bool
    {
        return $this->lastDay($date) >= $day;
    }

    /** Whether this says more of a date than the other: a day more than a month, a month more than a year. */
    public function isFinerThan(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Day => 0,
            self::Month => 1,
            self::Quarter => 2,
            self::Year => 3,
        };
    }
}
