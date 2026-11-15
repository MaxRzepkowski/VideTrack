<?php

namespace App\Suggestions\Sources;

use App\Catalog\ChartOrder;

/**
 * The most widely known titles the user has not seen.
 */
final readonly class BestKnownUnseen extends ChartUnseen
{
    public function title(): string
    {
        return __('suggestions.best_known_unseen');
    }

    public function description(): string
    {
        return __('suggestions.best_known_unseen_rule');
    }

    protected function order(): ChartOrder
    {
        return ChartOrder::MostVoted;
    }

    protected function depth(): int
    {
        return config()->integer('tracker.suggestions.chart_depth.best_known');
    }
}
