<?php

namespace App\Tasks\Maintenance;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** How a line of a check reads: all is well, it passes on its own, or the admin has to act. */
#[TypeScript]
enum CheckLevel: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Problem = 'problem';
}
