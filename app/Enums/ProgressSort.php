<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The sort axes of the "in progress" panel of a user view. */
#[TypeScript]
enum ProgressSort: string
{
    /** What was touched last — what the shelves are about. */
    case Activity = 'activity';

    /**
     * The share of the title still ahead: the one number a show, a book and
     * a game can all be compared on.
     */
    case Remaining = 'remaining';

    case Title = 'title';
}
