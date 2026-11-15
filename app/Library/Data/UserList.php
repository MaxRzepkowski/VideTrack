<?php

namespace App\Library\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the signed-in user's lists, as the lists modal on every page offers it.
 */
#[TypeScript]
final readonly class UserList
{
    public function __construct(
        public int $id,
        /** As the reader sees it: the watchlist named in their language. */
        public string $title,
        public ?string $systemKey,
    ) {}
}
