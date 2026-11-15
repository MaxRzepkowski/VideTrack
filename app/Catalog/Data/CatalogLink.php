<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A catalog entity a title is tied to (its franchise, its platform), as its details name it: enough to open its page. */
#[TypeScript]
final readonly class CatalogLink implements JsonSerializable
{
    public function __construct(
        public string $ref,
        public string $name,
    ) {}

    /**
     * @return array{
     *     ref: string,
     *     name: string
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'ref' => $this->ref,
            'name' => $this->name,
        ];
    }
}
