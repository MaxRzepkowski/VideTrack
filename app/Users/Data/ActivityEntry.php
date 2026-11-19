<?php

namespace App\Users\Data;

use App\Catalog\Data\MediaSummary;
use App\Enums\PlayOutcome;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One card of a log panel of a user view: history, ratings or favorites. */
#[TypeScript]
final readonly class ActivityEntry
{
    public function __construct(
        /** The entry behind the card; null when the card stands for several (a show rolled up from its episodes). */
        public ?int $id,
        /** When the entry happened, as the panel dates it. */
        public ?string $at,
        public MediaSummary $media,
        /** Day key (YYYY-MM-DD) the server paginated by, in "grouped by day" mode. */
        public ?string $day = null,
        /** The ratings panel: the score the entry gave. */
        public ?int $rating = null,
        /** The history panel: how the attempt closed. */
        public ?PlayOutcome $outcome = null,
    ) {}
}
