<?php

namespace App\Http\Data;

use App\Users\Statistics\Data\CraftPeople;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Who made a period's titles: its cast, directors, writers and authors, each craft that credits anyone. */
#[TypeScript]
final readonly class StatisticsCreators
{
    /**
     * @param  list<CraftPeople>  $crafts  cast, directing, writing, authors, in that order
     */
    public function __construct(
        public array $crafts,
    ) {}
}
