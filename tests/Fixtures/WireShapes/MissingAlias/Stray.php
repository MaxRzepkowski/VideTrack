<?php

namespace Tests\Fixtures\WireShapes\MissingAlias;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tests\Fixtures\WireShapes\Described\Card;

/**
 * @phpstan-import-type Pouch from Card
 */
#[TypeScript]
final readonly class Stray implements JsonSerializable
{
    /** @return array{pouch: Pouch} */
    public function jsonSerialize(): array
    {
        return ['pouch' => []];
    }
}
