<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Days in a row with something finished on each (Y-m-d, the user's clock),
 * and the weeks around them, cut to the period, with the other days anything
 * was finished on.
 */
#[TypeScript]
final readonly class Streak
{
    /**
     * @param  list<string>  $otherDays  days around the streak with something finished
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $days,
        public string $shownFrom,
        public string $shownTo,
        public array $otherDays,
    ) {}
}
