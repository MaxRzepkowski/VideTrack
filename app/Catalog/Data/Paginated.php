<?php

namespace App\Catalog\Data;

use App\Support\PageInfo;
use InvalidArgumentException;

/**
 * One numbered page of a listing. `total` counts the whole set behind it, not
 * the page; `totalPages` is what the provider will actually serve, which can
 * stop short of `total` (TMDB answers no further than page 500). Both pages
 * are at least 1, also for an empty set, so a caller never clamps.
 *
 * @template T
 */
final readonly class Paginated
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $totalPages,
        public int $total,
    ) {
        if ($page < 1 || $totalPages < 1 || $total < 0) {
            throw new InvalidArgumentException("A listing has pages from 1 and no negative total [page {$page} of {$totalPages}, {$total} in all].");
        }
    }

    public function pageInfo(): PageInfo
    {
        return new PageInfo($this->page, $this->totalPages, $this->total);
    }
}
