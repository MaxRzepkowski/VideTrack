<?php

namespace App\Users\Statistics;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What one column of the "activity over time" chart holds: a day, a week, a month or a year, by how long the period is. */
#[TypeScript]
enum ActivityBucket: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
