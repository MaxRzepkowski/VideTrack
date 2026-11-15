<?php

namespace App\Catalog\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A single streaming/rent/buy service offering a title in some region.
 */
#[TypeScript]
final readonly class WatchProviderData implements JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $logoPath = null,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     logoPath: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'logoPath' => $this->logoPath,
        ];
    }
}
