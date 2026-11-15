<?php

namespace App\Integrations\Import\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A custom list the input carries, offered opt-in: an export can hold
 * hundreds, and every item costs a provider round-trip.
 */
#[TypeScript]
final readonly class ManifestList
{
    public function __construct(
        public string $id,
        public string $name,
        public int $itemCount,
        public bool $isPublic,
    ) {}

    /**
     * @return array{id: string, name: string, itemCount: int, isPublic: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'itemCount' => $this->itemCount,
            'isPublic' => $this->isPublic,
        ];
    }
}
