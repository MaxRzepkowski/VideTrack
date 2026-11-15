<?php

namespace Tests\Fixtures\WireShapes\Described\Kinds;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Known only to Card by its `use`: Shelf, importing Card's alias, does not name it. */
#[TypeScript]
enum Mood: string
{
    case Calm = 'calm';
}
