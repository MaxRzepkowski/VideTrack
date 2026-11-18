<?php

namespace App\Http\Data;

use App\Catalog\Data\TypeCount;
use App\Library\Data\WatchStats;
use App\Users\Statistics\Data\Bookend;
use App\Users\Statistics\Data\PeriodSpan;
use App\Users\Statistics\Data\PeriodSummary;
use App\Users\Statistics\Data\PeriodTitles;
use App\Users\Statistics\Data\YearCount;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The top of the statistics page: the period picker, the period's card, its tiles, titles and bookends. */
#[TypeScript]
final readonly class StatisticsPage
{
    /**
     * @param  list<YearCount>  $years  every year something of the chosen type was finished in, oldest first
     * @param  list<TypeCount>  $types  every type with titles, with what was finished in the period
     * @param  array<string, WatchStats>  $tiles  per root type, in the registry's order
     */
    public function __construct(
        public PeriodSpan $period,
        /** The chosen root type; null for every type. */
        public ?string $type,
        public array $years,
        /** What of the chosen type was finished over the whole history. */
        public int $allTime,
        public array $types,
        /** The day something of the chosen type was first finished, Y-m-d. */
        public ?string $earliest,
        public PeriodSummary $summary,
        public array $tiles,
        public PeriodTitles $titles,
        public ?Bookend $first,
        public ?Bookend $last,
    ) {}
}
