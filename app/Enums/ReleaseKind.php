<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which of a film's release moments one calendar entry announces. A
 * day-and-date release (both on one day) is one entry for the two, so the
 * calendar never stacks the same title twice on a single day.
 */
#[TypeScript]
enum ReleaseKind: string
{
    case Premiere = 'premiere';
    case Home = 'home';
    case Both = 'both';
}
