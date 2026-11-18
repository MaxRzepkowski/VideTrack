<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Data\MediaSummary;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The first or the last thing finished in a period. */
#[TypeScript]
final readonly class Bookend
{
    public function __construct(
        /** What was finished, in its title's context (an episode names its show). */
        public MediaSummary $media,
        /** ISO 8601 in the user's timezone. */
        public string $at,
    ) {}
}
