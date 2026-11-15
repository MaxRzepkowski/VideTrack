<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** How an activity panel lays its entries out, each with its own paging. */
#[TypeScript]
enum ActivityGrouping: string
{
    /** One grid, paged by entries. */
    case Grid = 'grid';

    /** Day headings, paged by days so a day never splits across pages. */
    case Day = 'day';
}
