<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The day the most was finished on, and the week around it, day by day. */
#[TypeScript]
final readonly class BusiestDay
{
    /**
     * @param  list<DayCount>  $week  seven days from the first weekday of the user's week, out of the period too
     */
    public function __construct(
        public DayCount $day,
        public array $week,
    ) {}
}
