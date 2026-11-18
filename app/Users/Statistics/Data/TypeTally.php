<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Data\TypeCount;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A total and how it splits by media type, in the registry's order. */
#[TypeScript]
final readonly class TypeTally
{
    /**
     * @param  list<TypeCount>  $byType  only the types with something to count
     */
    public function __construct(
        public int $total,
        public array $byType,
    ) {}
}
