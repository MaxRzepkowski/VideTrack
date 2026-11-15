<?php

namespace App\Library;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What settling an Up Next card's episode empties: the whole show (the
 * finale of one that ended) or what is known of it (the last known season's
 * finale of one still returning).
 */
#[TypeScript]
enum ClosesRun: string
{
    case Series = 'series';
    case Season = 'season';
}
