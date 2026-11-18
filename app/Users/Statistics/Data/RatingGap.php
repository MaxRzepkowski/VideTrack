<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Data\MediaSummary;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A title the user rated apart from everyone else, with both ratings: the user's on the rating scale, everyone else's average 0–10. */
#[TypeScript]
final readonly class RatingGap
{
    public function __construct(
        public MediaSummary $media,
        public int $rating,
        /** Everyone else's average on the service, to one decimal. */
        public float $community,
    ) {}
}
