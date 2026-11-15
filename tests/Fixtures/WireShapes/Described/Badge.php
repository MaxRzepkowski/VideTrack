<?php

namespace Tests\Fixtures\WireShapes\Described;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class Badge implements JsonSerializable
{
    public function __construct(public string $label) {}

    /** @return array{label: string} */
    public function jsonSerialize(): array
    {
        return ['label' => $this->label];
    }
}
