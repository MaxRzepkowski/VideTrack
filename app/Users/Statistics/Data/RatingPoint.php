<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** How many of the period's titles of one type the user rated one way and everyone else another. */
#[TypeScript]
final readonly class RatingPoint
{
    public function __construct(
        public string $type,
        /** The user's rating, 1–10. */
        public int $rating,
        /** Everyone else's average on the service, 0–10, to one decimal. */
        public float $community,
        public int $titles,
    ) {}
}
