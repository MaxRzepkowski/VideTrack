<?php

namespace App\Accounts\Preferences;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where an Up Next card leads when clicked.
 */
#[TypeScript]
enum UpNextTarget: string
{
    case Episode = 'episode';
    case Season = 'season';
    case Show = 'show';
}
