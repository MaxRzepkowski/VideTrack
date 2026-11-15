<?php

namespace App\Tracking\Data;

use App\Enums\CountdownSort;
use App\Enums\SortDirection;

/**
 * The countdowns page's controls as its address carries them. `type` is only
 * a wish: the page keeps it when that type is counting down at all.
 */
final readonly class CountdownFilters
{
    public function __construct(
        public CountdownSort $sort = CountdownSort::Premiere,
        public SortDirection $dir = SortDirection::Asc,
        public ?string $type = null,
        public ?string $q = null,
    ) {}
}
