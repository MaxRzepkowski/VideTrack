<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Data\MediaSummary;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A title of the period with the user's rating of it; everyone else's rides on the card. */
#[TypeScript]
final readonly class RatedTitle
{
    public function __construct(
        public MediaSummary $media,
        /** The user's rating, 1–10. */
        public ?int $rating,
    ) {}
}
