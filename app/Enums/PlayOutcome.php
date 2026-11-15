<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How an attempt ended. A play row records one CLOSED attempt at a title —
 * completing it or abandoning it. Open attempts (playing / on hold) live in
 * media_user_states until they close into a play.
 */
#[TypeScript]
enum PlayOutcome: string
{
    case Completed = 'completed';
    case Dropped = 'dropped';
}
