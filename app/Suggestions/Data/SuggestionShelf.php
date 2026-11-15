<?php

namespace App\Suggestions\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A kind of suggestion on the suggestions page: its rule and how many titles
 * wait behind it.
 */
#[TypeScript]
final readonly class SuggestionShelf
{
    /**
     * @param  list<string>  $posters  a few posters of its first titles
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public int $total,
        public array $posters,
    ) {}
}
