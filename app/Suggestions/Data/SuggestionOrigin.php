<?php

namespace App\Suggestions\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the ways a title gets onto a shelf (added to a list, belled), to
 * narrow the shelf by.
 */
#[TypeScript]
final readonly class SuggestionOrigin
{
    public function __construct(
        public string $key,
        public string $label,
        /** Titles on the whole shelf that came this way. */
        public int $count,
    ) {}
}
