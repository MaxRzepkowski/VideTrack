<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Data\MediaSummary;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A title and how much of the period went to it. */
#[TypeScript]
final readonly class TimeTitle
{
    public function __construct(
        public MediaSummary $media,
        /** Minutes, or the type's own unit for what is read. */
        public int $amount,
        /** Minutes its reading took, estimated at the user's pace; null for a title that was not read. */
        public ?int $estimatedMinutes,
        /** How many of its parts were finished (episodes of a show); null for a title finished whole. */
        public ?int $parts,
    ) {}
}
