<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The sort axes of the countdowns page; the type is what its filter is for. */
#[TypeScript]
enum CountdownSort: string
{
    /** Soonest first — the order the list is built in. */
    case Premiere = 'premiere';

    case Title = 'title';
}
