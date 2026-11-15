<?php

namespace App\Library\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One dashboard statistics tile: a root type's time and counts.
 */
#[TypeScript]
final readonly class WatchStats
{
    public function __construct(
        public int $minutes,
        /** Watched items: completed ones, or every attempt for a type with a progress counter. */
        public int $watched,
        public int $plays,
        /** Distinct root titles. */
        public int $titles,
    ) {}
}
