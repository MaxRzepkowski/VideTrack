<?php

namespace App\Accounts\Data;

use App\Catalog\MatureContent;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One provider's row in the adult content settings: the kinds of title it
 * serves (what the row is called by), the levels it tells apart and the one
 * the viewer keeps to.
 */
#[TypeScript]
final readonly class MatureContentOption
{
    /**
     * @param  list<string>  $types
     * @param  non-empty-list<MatureContent>  $levels
     */
    public function __construct(
        public string $provider,
        public array $types,
        public array $levels,
        public MatureContent $level,
    ) {}
}
