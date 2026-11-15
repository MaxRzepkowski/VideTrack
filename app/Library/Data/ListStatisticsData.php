<?php

namespace App\Library\Data;

use App\Catalog\Data\TypeCount;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A list's fixed totals; filters and pagination never change them.
 */
#[TypeScript]
final readonly class ListStatisticsData
{
    /**
     * @param  list<TypeCount>  $types  positions per media type, biggest group first
     * @param  list<ListUnitTotal>  $totals  one per unit the list measures in; empty when no size is known
     */
    public function __construct(
        /** Every position, people and companies included. */
        public int $itemCount,
        public array $types,
        /** Media positions that can be completed; people and companies are not. */
        public int $trackableCount,
        /** Completed media positions, for the viewing user. */
        public int $completedCount,
        /** Started but not finished; a dropped attempt is neither. */
        public int $inProgressCount,
        /** Given up on: still in the denominator, never reaching it. */
        public int $droppedCount,
        /** Not out yet and not finished: off the progress until it is. */
        public int $upcomingCount,
        public array $totals,
    ) {}
}
