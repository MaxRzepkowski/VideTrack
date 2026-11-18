<?php

namespace App\Users\Statistics\Data;

use App\Catalog\Data\MediaSummary;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** How long the titles watched whole (films) of a period were. */
#[TypeScript]
final readonly class WholeLength
{
    public function __construct(
        public string $type,
        public int $titles,
        /** Minutes, every viewing counted. */
        public int $total,
        public int $average,
        public MediaSummary $longest,
        public int $longestRuntime,
    ) {}
}
