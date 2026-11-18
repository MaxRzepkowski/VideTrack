<?php

namespace App\Http\Data;

use App\Users\Statistics\Data\RatedTitle;
use App\Users\Statistics\Data\RatingGap;
use App\Users\Statistics\Data\RatingPoint;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** How the user rated a period's titles against everyone else, its best rated titles and where the two differ most. */
#[TypeScript]
final readonly class StatisticsRatings
{
    /**
     * @param  list<RatingPoint>  $points  the rated titles everyone else rated too
     * @param  list<RatedTitle>  $best  highest rated first, a tie by name
     * @param  list<RatingGap>  $higher  rated furthest above everyone else first
     * @param  list<RatingGap>  $lower  rated furthest below everyone else first
     */
    public function __construct(
        public array $points,
        /** Of those, how many the user rated above everyone else's average, below it, and exactly at it. */
        public int $ratedHigher,
        public int $ratedLower,
        public int $ratedSame,
        public array $best,
        /** How many titles share the highest rating, shown or not. */
        public int $bestTied,
        public array $higher,
        public array $lower,
    ) {}
}
