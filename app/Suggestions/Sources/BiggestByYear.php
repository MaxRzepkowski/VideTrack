<?php

namespace App\Suggestions\Sources;

/**
 * The best known releases of each recent year that the user has not seen.
 */
final readonly class BiggestByYear extends BiggestOfPeriods
{
    /** Years back from this one that get a chart of their own; older ones are read by decade. */
    public const YEARS = 20;

    public function title(): string
    {
        return __('suggestions.biggest_by_year');
    }

    public function description(): string
    {
        return __('suggestions.biggest_by_year_rule');
    }

    protected function periods(): array
    {
        $thisYear = today()->year;

        return array_map(fn (int $back): array => ['from' => $thisYear - $back, 'to' => $thisYear - $back], range(0, self::YEARS - 1));
    }

    protected function depth(): int
    {
        return config()->integer('tracker.suggestions.chart_depth.per_year');
    }
}
