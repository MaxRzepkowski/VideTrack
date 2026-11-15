<?php

namespace Tests\Fixtures\WireShapes\Described;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** No wire form of its own: its properties are what is sent. */
#[TypeScript]
final readonly class Point
{
    public function __construct(public int $x, public int $y) {}
}
