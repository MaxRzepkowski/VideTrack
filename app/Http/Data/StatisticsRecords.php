<?php

namespace App\Http\Data;

use App\Users\Statistics\Data\BusiestDay;
use App\Users\Statistics\Data\Marathon;
use App\Users\Statistics\Data\Streak;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The period's records: the longest run of days with something finished, the
 * busiest day and the most of one title in a day. Each is null when the
 * period has nothing worth the name.
 */
#[TypeScript]
final readonly class StatisticsRecords
{
    public function __construct(
        public ?Streak $streak,
        public ?BusiestDay $busiestDay,
        public ?Marathon $marathon,
        /** The weekday the user's week opens on (ISO: 1 is Monday, 7 Sunday): the busiest day's week and the streak's calendar start there. */
        public int $firstWeekday,
    ) {}
}
