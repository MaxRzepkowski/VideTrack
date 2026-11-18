<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A day (Y-m-d, the user's clock) and how much was finished on it. */
#[TypeScript]
final readonly class DayCount
{
    public function __construct(
        public string $day,
        public int $count,
    ) {}
}
