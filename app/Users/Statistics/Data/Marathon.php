<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Data\MediaSummary;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The most parts of one title finished in one day: a show's episodes. */
#[TypeScript]
final readonly class Marathon
{
    public function __construct(
        public MediaSummary $media,
        /** Y-m-d, the user's clock. */
        public string $day,
        public int $parts,
        /** The lowest and the highest of the parts, by season and number. */
        public PartNumber $first,
        public PartNumber $last,
        public int $minutes,
    ) {}
}
