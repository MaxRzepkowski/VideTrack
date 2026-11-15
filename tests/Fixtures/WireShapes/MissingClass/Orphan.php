<?php

namespace Tests\Fixtures\WireShapes\MissingClass;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * @phpstan-import-type Bag from Nowhere
 */
#[TypeScript]
final readonly class Orphan implements JsonSerializable
{
    /** @return array{bag: Bag} */
    public function jsonSerialize(): array
    {
        return ['bag' => []];
    }
}
