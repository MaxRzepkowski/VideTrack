<?php

namespace Tests\Fixtures\WireShapes\Undescribed;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class Loose implements JsonSerializable
{
    public function __construct(public string $name) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name];
    }
}
