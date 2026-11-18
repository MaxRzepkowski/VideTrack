<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The period's card beside the marks: the time it took, on how many days something was finished, how the titles were rated. */
#[TypeScript]
final readonly class PeriodSummary
{
    /**
     * @param  list<int>  $ratingCounts
     */
    public function __construct(
        /** Minutes per type, `total` across them: watched and played as logged, read as estimated. */
        public TypeTally $time,
        /** Of those, the minutes estimated from what was read. */
        public int $readingMinutes,
        public int $activeDays,
        /** The period's titles the user rated, one rating per title. */
        public int $ratings,
        public ?float $averageRating,
        /** How many titles got each rating, from the scale's lowest to its highest. */
        public array $ratingCounts,
        /** One type's view: the user's ratings against everyone else's, on average (see BuildStatisticsPage); null otherwise. */
        public ?float $communityDelta,
    ) {}
}
