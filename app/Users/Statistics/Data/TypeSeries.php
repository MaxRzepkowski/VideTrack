<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One type's counts over the buckets of a chart. */
#[TypeScript]
final readonly class TypeSeries
{
    /**
     * @param  list<int>  $counts  one per bucket
     */
    public function __construct(
        public string $type,
        public array $counts,
    ) {}
}
