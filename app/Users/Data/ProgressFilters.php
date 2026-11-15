<?php

namespace App\Users\Data;

use App\Enums\MediaUserStatus;
use App\Enums\ProgressSort;
use App\Enums\SortDirection;

/**
 * The "in progress" panel's controls as its address carries them. `type` is
 * only a wish: the panel keeps it when that type is on the shelves at all.
 */
final readonly class ProgressFilters
{
    public function __construct(
        public ProgressSort $sort = ProgressSort::Activity,
        public SortDirection $dir = SortDirection::Asc,
        public ?string $type = null,
        /** In progress or on hold; null shows both shelves. */
        public ?MediaUserStatus $status = null,
        public ?string $q = null,
    ) {}
}
