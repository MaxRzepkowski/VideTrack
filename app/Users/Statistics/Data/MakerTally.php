<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Data\CreatorLink;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A network or a studio behind the period's titles: how many of them it made or ran, and the best rated of those. */
#[TypeScript]
final readonly class MakerTally
{
    public function __construct(
        public CreatorLink $creator,
        public int $titles,
        /** @var list<int> places in the origins' `titles`, best rated first */
        public array $best,
    ) {}
}
