<?php

namespace App\Http\Data;

use App\Users\Statistics\ActivityBucket;
use App\Users\Statistics\Data\DayCount;
use App\Users\Statistics\Data\TypeSeries;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * When things were finished in a period: over its months (or years), day by
 * day, by weekday and by hour of the day, on the user's clock.
 */
#[TypeScript]
final readonly class StatisticsActivity
{
    /**
     * @param  list<string>  $buckets  each column's day ('YYYY-MM-DD', a week's by its first day), month ('YYYY-MM') or year ('YYYY')
     * @param  list<TypeSeries>  $series  per type the user has a title of (only the chosen one, if any), in the registry's order
     * @param  array<string, int>|null  $days  Y-m-d => count, for a period short enough to draw day by day
     * @param  array<string, int>|null  $months  'YYYY-MM' => count, for a period drawn as years by months
     * @param  list<int>  $weekdays  Monday first
     * @param  list<int>  $hours  0–23
     */
    public function __construct(
        /** What a column holds, by how long the period is. */
        public ActivityBucket $bucket,
        public array $buckets,
        public array $series,
        public int $completions,
        /** Minutes watched and played. */
        public int $watchMinutes,
        /** Minutes read, estimated at the user's reading pace (see ReadingTime). */
        public int $readingMinutes,
        public ?array $days,
        public ?array $months,
        public array $weekdays,
        public array $hours,
        public ?DayCount $busiestDay,
        /** The weekday the user's week opens on (ISO: 1 is Monday, 7 Sunday): weeks, the weekday chart and the calendar of days start there. */
        public int $firstWeekday,
    ) {}
}
