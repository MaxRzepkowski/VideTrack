<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One genre a provider can list titles by. Genres travel as an ordered list
 * rather than a ref => name map for the reason DiscoverFilterOption gives:
 * numeric refs would re-order a JavaScript object, and the provider's order
 * is the one to show.
 */
#[TypeScript]
final readonly class GenreOption implements JsonSerializable
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
