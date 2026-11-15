<?php

namespace App\Accounts\Preferences;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which roles of a followed company feed auto-follows — a guard against a
 * prolific publisher's flood.
 */
#[TypeScript]
enum CompanyFollowScope: string
{
    case Developed = 'developed';
    case Published = 'published';

    /** A studio that builds and ships a game counts either way. */
    case Both = 'both';
}
