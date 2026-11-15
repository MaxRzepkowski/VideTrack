<?php

namespace App\Catalog\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The banner a title's page shows for the collection it belongs to (a film
 * saga, a game series).
 */
#[TypeScript]
final readonly class CollectionBannerData
{
    public function __construct(
        public string $provider,
        public string $ref,
        public string $name,
        public ?string $backdropPath,
        /** Unknown when the provider did not answer for the whole collection. */
        public ?int $partCount,
        /** The media type of its parts, which the count is said in ("5 films", "5 games"). */
        public string $partType,
    ) {}
}
