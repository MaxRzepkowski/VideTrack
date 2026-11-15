<?php

namespace App\Suggestions\Sources;

/**
 * The best known titles of each decade that the user has not seen.
 */
final readonly class BiggestByDecade extends BiggestOfPeriods
{
    /** The first decade charted: before it too few titles are widely known to fill a chart. */
    public const FIRST_DECADE = 1930;

    public function title(): string
    {
        return __('suggestions.biggest_by_decade');
    }

    public function description(): string
    {
        return __('suggestions.biggest_by_decade_rule');
    }

    protected function periods(): array
    {
        $decades = range(intdiv(today()->year, 10) * 10, self::FIRST_DECADE, -10);

        return array_map(fn (int $decade): array => ['from' => $decade, 'to' => $decade + 9], $decades);
    }

    protected function depth(): int
    {
        return config()->integer('tracker.suggestions.chart_depth.per_decade');
    }
}
