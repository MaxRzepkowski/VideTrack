<?php

namespace App\Tasks\Attention\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Something the admin has to fix, worded for them: what is wrong, what to do, and since when. */
#[TypeScript]
final readonly class AttentionItem
{
    public function __construct(
        public string $title,
        public string $detail,
        public ?string $since,
    ) {}
}
