<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How far a listing may go: the highest rating of material it lets in.
 * Providers file adult material in their own words (an `adult` flag, an NSFW
 * grade); this is the one scale the core speaks. A provider that files no
 * adult material has nothing to keep out and ignores the level.
 */
#[TypeScript]
enum MatureContent: string
{
    /** Nothing suggestive either. */
    case Safe = 'safe';

    /** Everything short of adult material. */
    case Suggestive = 'suggestive';

    /** Adult material too. */
    case Adult = 'adult';

    public function allowsAdult(): bool
    {
        return $this === self::Adult;
    }

    public function allowsSuggestive(): bool
    {
        return $this !== self::Safe;
    }
}
