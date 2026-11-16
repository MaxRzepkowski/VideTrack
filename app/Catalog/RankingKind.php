<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Which of a provider's rankings a position is on: the one by score, or the one by how many people follow the title. */
#[TypeScript]
enum RankingKind: string
{
    case Score = 'score';
    case Popularity = 'popularity';
}
