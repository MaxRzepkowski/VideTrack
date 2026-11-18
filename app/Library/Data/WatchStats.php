<?php

namespace App\Library\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One statistics tile (the dashboard's, a profile's, the statistics page's):
 * a root type's time and counts.
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
        /** For a type that is read, the minutes its reading took, estimated at the user's pace (ReadingTime); null otherwise. */
        public ?int $estimatedMinutes = null,
    ) {}
}
