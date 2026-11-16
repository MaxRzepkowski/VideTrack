<?php

namespace App\Tracking\Surfaces;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** How much of the calendar a subscription feed carries. */
#[TypeScript]
enum FeedScope: string
{
    /** Every release and event the calendar shows. */
    case All = 'all';

    /** Titles on their premiere and the episodes opening a season; no other episode, no film leaving cinemas, no event. */
    case Premieres = 'premieres';
}
