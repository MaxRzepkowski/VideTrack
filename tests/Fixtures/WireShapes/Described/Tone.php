<?php

namespace Tests\Fixtures\WireShapes\Described;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum Tone: string
{
    case Warm = 'warm';
    case Cold = 'cold';
}
