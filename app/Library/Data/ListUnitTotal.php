<?php

namespace App\Library\Data;

use App\Catalog\ProgressUnit;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How much of one unit a list holds. Units never add up with each other:
 * hours of film and pages of a book are separate totals, one chip each.
 */
#[TypeScript]
final readonly class ListUnitTotal
{
    public function __construct(
        public ProgressUnit $unit,
        /** The size comes from a provider estimate (a game's completion time). */
        public bool $estimated,
        /** Sum over the positions whose size the provider knows. */
        public int $amount,
        /** The part already behind the user: finished items plus open counters. */
        public int $behindAmount,
        /** The rest of `amount`. */
        public int $remainingAmount,
        /** Positions with a known size, out of the positions using this unit. */
        public int $itemsCounted,
        public int $itemsTotal,
    ) {}
}
