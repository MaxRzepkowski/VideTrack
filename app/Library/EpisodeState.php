<?php

namespace App\Library;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Where an aired episode stands in a run, as the Up Next card's bar draws it. */
#[TypeScript]
enum EpisodeState: string
{
    case Watched = 'watched';

    /** Settled without being watched: a hole in the bar. */
    case Skipped = 'skipped';

    case Pending = 'pending';
}
