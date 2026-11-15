<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A follow row is a per-title (or per-person) exception to the tracking
 * matrix: `follow` forces the position into every consumer
 * whose "followed manually" source is on, `mute` cuts it out of all of them
 * regardless of which sources would include it.
 */
#[TypeScript]
enum FollowMode: string
{
    case Follow = 'follow';
    case Mute = 'mute';
}
