<?php

namespace App\Catalog\Contracts;

use App\Catalog\ChartOrder;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;

/**
 * Capability: the provider can rank its whole catalogue of a type, or the
 * titles released within a span of years, the same way on any day (unlike
 * trending, which is this week's mood).
 */
interface SupportsCharts
{
    /**
     * @return list<string> the type codes it charts
     */
    public function chartedTypes(): array;

    /**
     * One page of a chart, the top first.
     *
     * @param  int|null  $fromYear  first year of release counted; null for no floor
     * @param  int|null  $toYear  last year of release counted; null for no ceiling
     * @return Paginated<MediaSummary>
     */
    public function chart(string $type, ChartOrder $order, ?int $fromYear, ?int $toYear, string $locale, int $page = 1): Paginated;
}
