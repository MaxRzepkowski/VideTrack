<?php

namespace App\Actions\Users;

use App\Accounts\UserCalendar;
use App\Catalog\MediaTypeRegistry;
use App\Http\Data\StatisticsActivity;
use App\Library\ReadingTime;
use App\Models\User;
use App\Users\Statistics\ActivityBucket;
use App\Users\Statistics\DailyCounts;
use App\Users\Statistics\Data\HistoryEntry;
use App\Users\Statistics\Data\TypeSeries;
use App\Users\Statistics\PeriodKind;
use App\Users\Statistics\StatisticsScope;
use Carbon\CarbonImmutable;

/**
 * The "activity over time" section: what was finished per day, week, month
 * or year (by how long the period is) and type, per day, per weekday and per
 * hour. Bulk entries count like any other — the history is what it is; the
 * charts are drawn to withstand a spike.
 */
final readonly class BuildStatisticsActivity
{
    /** Longest period whose chart still has a column per day. */
    private const DAY_COLUMNS_UP_TO_DAYS = 31;

    /** Longest period whose chart still has a column per week: about a quarter. */
    private const WEEK_COLUMNS_UP_TO_DAYS = 92;

    /** Longest period whose chart still has a column per month. */
    private const MONTH_COLUMNS_UP_TO_MONTHS = 24;

    /** Longest period still drawn day by day below the chart. */
    private const DAILY_UP_TO_DAYS = 400;

    public function __construct(private MediaTypeRegistry $types) {}

    public function execute(User $viewer, StatisticsScope $scope): StatisticsActivity
    {
        $reading = new ReadingTime($viewer->preferences);

        $span = $scope->span();
        $from = $span->from === null ? null : CarbonImmutable::parse($span->from);
        $to = $span->kind === PeriodKind::All || $span->to === null ? CarbonImmutable::parse($scope->today) : CarbonImmutable::parse($span->to);
        $firstWeekday = $viewer->preferences->firstWeekday($viewer->preferredLocale());

        $size = $from === null ? ActivityBucket::Month : $this->bucketSize($from, $to);
        $buckets = $from === null ? [] : $this->buckets($size, $from, $to, $firstWeekday);

        // Below a chart of days, a calendar of the same days would say it again.
        $daily = $from !== null && $size !== ActivityBucket::Day && $from->diffInDays($to) < self::DAILY_UP_TO_DAYS;
        $monthGrid = $from !== null && $size !== ActivityBucket::Day && ! $daily;

        $perDay = DailyCounts::of($scope->completions);
        $series = [];
        $perMonth = [];
        $weekdays = array_fill(0, 7, 0);
        $hours = array_fill(0, 24, 0);
        $index = array_flip($buckets);

        foreach ($scope->completions as $entry) {
            $bucket = $this->bucketOf($size, $entry->day, $firstWeekday);
            $series[$entry->rootType] ??= array_fill(0, count($buckets), 0);

            if (isset($index[$bucket])) {
                $series[$entry->rootType][$index[$bucket]]++;
            }

            $month = substr($entry->day, 0, 7);
            $perMonth[$month] = ($perMonth[$month] ?? 0) + 1;
            $weekdays[$entry->at->dayOfWeekIso - 1]++;
            $hours[$entry->at->hour]++;
        }

        return new StatisticsActivity(
            bucket: $size,
            buckets: $buckets,
            series: array_map(
                fn (string $code): TypeSeries => new TypeSeries($code, $series[$code] ?? array_fill(0, count($buckets), 0)),
                $this->seriesTypes($scope),
            ),
            completions: count($scope->completions),
            watchMinutes: array_sum(array_map(fn (HistoryEntry $entry): int => $entry->loggedMinutes(), $scope->shown)),
            readingMinutes: array_sum(array_map(fn (HistoryEntry $entry): int => $entry->readMinutes($reading) ?? 0, $scope->shown)),
            days: $daily ? $perDay->counts : null,
            months: $monthGrid ? $perMonth : null,
            weekdays: array_values($weekdays),
            hours: array_values($hours),
            busiestDay: $perDay->busiest(),
            firstWeekday: $firstWeekday,
        );
    }

    /**
     * The types that get a chart: the chosen one, or every type the user has
     * a title of, so a type with nothing finished in the period shows as an
     * empty chart rather than vanishing.
     *
     * @return list<string>
     */
    private function seriesTypes(StatisticsScope $scope): array
    {
        if ($scope->type !== null) {
            return [$scope->type];
        }

        $present = [];

        foreach ($scope->everything as $entry) {
            $present[$entry->rootType] = true;
        }

        return $this->types->ordered(array_keys($present));
    }

    /** A column per day for a month at most, per week for a quarter, per month for two years, per year beyond. */
    private function bucketSize(CarbonImmutable $from, CarbonImmutable $to): ActivityBucket
    {
        $days = (int) $from->diffInDays($to) + 1;
        $months = (int) $from->startOfMonth()->diffInMonths($to->startOfMonth()) + 1;

        return match (true) {
            $days <= self::DAY_COLUMNS_UP_TO_DAYS => ActivityBucket::Day,
            $days <= self::WEEK_COLUMNS_UP_TO_DAYS => ActivityBucket::Week,
            $months <= self::MONTH_COLUMNS_UP_TO_MONTHS => ActivityBucket::Month,
            default => ActivityBucket::Year,
        };
    }

    /**
     * Every column from the first day to the last, named as bucketOf() names
     * a day's: 'Y-m-d' for a day and for a week (its first day), 'Y-m' for a
     * month, 'Y' for a year.
     *
     * @return list<string>
     */
    private function buckets(ActivityBucket $size, CarbonImmutable $from, CarbonImmutable $to, int $firstWeekday): array
    {
        $buckets = [];
        $last = $this->bucketOf($size, $to->toDateString(), $firstWeekday);
        $cursor = CarbonImmutable::parse($this->bucketStart($size, $from->toDateString(), $firstWeekday));

        do {
            $bucket = $this->bucketOf($size, $cursor->toDateString(), $firstWeekday);
            $buckets[] = $bucket;
            $cursor = match ($size) {
                ActivityBucket::Day => $cursor->addDay(),
                ActivityBucket::Week => $cursor->addWeek(),
                ActivityBucket::Month => $cursor->addMonthNoOverflow(),
                ActivityBucket::Year => $cursor->addYear(),
            };
        } while ($bucket !== $last);

        return $buckets;
    }

    /** The column a day (Y-m-d) falls in. */
    private function bucketOf(ActivityBucket $size, string $day, int $firstWeekday): string
    {
        return match ($size) {
            ActivityBucket::Day, ActivityBucket::Week => $this->bucketStart($size, $day, $firstWeekday),
            ActivityBucket::Month => substr($day, 0, 7),
            ActivityBucket::Year => substr($day, 0, 4),
        };
    }

    /** The first day (Y-m-d) of the column a day falls in. */
    private function bucketStart(ActivityBucket $size, string $day, int $firstWeekday): string
    {
        $date = CarbonImmutable::parse($day);

        return match ($size) {
            ActivityBucket::Day => $date->toDateString(),
            ActivityBucket::Week => UserCalendar::weekStart($date, $firstWeekday)->toDateString(),
            ActivityBucket::Month => $date->startOfMonth()->toDateString(),
            ActivityBucket::Year => $date->startOfYear()->toDateString(),
        };
    }
}
