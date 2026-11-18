<?php

namespace App\Http\Data;

use App\Catalog\ProgressUnit;
use App\Users\Statistics\Data\GenreStat;
use App\Users\Statistics\Data\ReleaseDot;
use App\Users\Statistics\Data\TimeTitle;
use App\Users\Statistics\Data\WholeLength;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What a period's titles were: where the time went, from which years, of which genres, how long. */
#[TypeScript]
final readonly class StatisticsTaste
{
    /**
     * @param  list<TimeTitle>  $top  the titles that took the most, most first
     * @param  list<ReleaseDot>  $releases  every title of the period with a release year
     * @param  list<GenreStat>  $genres  most titles first
     * @param  list<WholeLength>  $lengths  per type watched whole
     */
    public function __construct(
        /** The unit `top` and the genres' amounts are read in, for one type that is read; null when they are time. */
        public ?ProgressUnit $readIn,
        /** Whether time read, estimated, went into the times (see ReadingTime). */
        public bool $readingEstimated,
        public array $top,
        public array $releases,
        public array $genres,
        public array $lengths,
    ) {}
}
