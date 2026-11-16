<?php

namespace App\Catalog\Data;

use App\Catalog\RankingKind;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Where a title stands in one of its provider's rankings: #1 by score, #12 by popularity. */
#[TypeScript]
final readonly class RankingPosition
{
    public function __construct(
        public RankingKind $kind,
        public int $position,
    ) {}
}
