<?php

namespace App\Users\Statistics;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What stretch of the history the statistics page reads. */
#[TypeScript]
enum PeriodKind: string
{
    /** One calendar year in the user's timezone. */
    case Year = 'year';
    /** The whole history, from what was first finished to today. */
    case All = 'all';
    /** Two days the user picked, both included. */
    case Range = 'range';
}
