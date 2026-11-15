<?php

namespace App\Suggestions\Sources;

use App\Catalog\ChartOrder;

/**
 * The highest rated titles the user has not seen.
 */
final readonly class TopRatedUnseen extends ChartUnseen
{
    public function title(): string
    {
        return __('suggestions.top_rated_unseen');
    }

    public function description(): string
    {
        return __('suggestions.top_rated_unseen_rule');
    }

    protected function order(): ChartOrder
    {
        return ChartOrder::TopRated;
    }

    protected function depth(): int
    {
        return config()->integer('tracker.suggestions.chart_depth.top_rated');
    }
}
