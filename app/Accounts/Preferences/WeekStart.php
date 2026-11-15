<?php

namespace App\Accounts\Preferences;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The day the calendar's month grid starts its weeks on.
 */
#[TypeScript]
enum WeekStart: string
{
    /** The UI language's own week: Monday in Poland, Sunday in the US. */
    case Auto = 'auto';
    case Monday = 'monday';
    case Sunday = 'sunday';
}
