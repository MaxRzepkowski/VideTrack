<?php

namespace Tests\Fixtures\WireShapes\ShadowedName;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tests\Fixtures\WireShapes\ShadowedName\Kinds\Directory;

#[TypeScript]
final readonly class Listing implements JsonSerializable
{
    public function __construct(public Directory $dir) {}

    /** @return array{dir: value-of<Directory>} */
    public function jsonSerialize(): array
    {
        return ['dir' => $this->dir->value];
    }
}
