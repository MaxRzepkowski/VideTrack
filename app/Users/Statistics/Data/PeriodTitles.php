<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The titles of a period and what became of them. */
#[TypeScript]
final readonly class PeriodTitles
{
    public function __construct(
        /** Titles of the period: something of them counts in it (see UserHistory), or the user marked them in it. */
        public TypeTally $titles,
        /** The same titles by what each ended the period as; the four add up to `titles`. */
        public TypeTally $completed,
        public TypeTally $inProgress,
        public TypeTally $onHold,
        public TypeTally $dropped,
    ) {}
}
