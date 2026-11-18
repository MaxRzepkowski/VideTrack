<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One year on the page's year axis: how much was finished in it. */
#[TypeScript]
final readonly class YearCount
{
    public function __construct(
        public int $year,
        public int $completions,
    ) {}
}
