<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One title of a period on the release-year chart. */
#[TypeScript]
final readonly class ReleaseDot
{
    public function __construct(
        public int $year,
        public string $type,
        /** The user's rating, if any. */
        public ?int $rating,
    ) {}
}
