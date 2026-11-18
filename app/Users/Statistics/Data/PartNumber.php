<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Where a part sits in its title: an episode's number and its season's. */
#[TypeScript]
final readonly class PartNumber
{
    public function __construct(
        /** The number of the part's parent; null when the parent is the title itself. */
        public ?int $season,
        public ?int $number,
    ) {}
}
