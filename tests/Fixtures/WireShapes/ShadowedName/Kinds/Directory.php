<?php

namespace Tests\Fixtures\WireShapes\ShadowedName\Kinds;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Shares its short name with PHP's global `Directory` class. */
#[TypeScript]
enum Directory: string
{
    case Up = 'up';
    case Down = 'down';
}
