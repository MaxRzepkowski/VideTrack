<?php

namespace App\Accounts\Preferences;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum TimeFormat: string
{
    /** The UI language's own hour cycle. */
    case Auto = 'auto';
    case TwelveHour = '12';
    case TwentyFourHour = '24';
}
