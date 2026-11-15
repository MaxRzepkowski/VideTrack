<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * State of an OPEN attempt (attempt model): paused or actively played.
 * Closed attempts — completed or dropped — are play rows with a PlayOutcome,
 * never states; dropping converts the open state into a dropped play.
 */
#[TypeScript]
enum MediaUserStatus: string
{
    case OnHold = 'on_hold';

    /**
     * Manually set "currently playing / reading" — only for flat progress
     * types (games, books, manga, comics). Shows derive their in-progress state from
     * episode plays instead and never carry this value.
     */
    case InProgress = 'in_progress';
}
