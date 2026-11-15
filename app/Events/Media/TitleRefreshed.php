<?php

namespace App\Events\Media;

/**
 * A stored title's whole tree was read again from the provider: episodes may
 * have been added, dated, or given a length. Not a user's act; whatever is
 * worked out from the tree is worked out again from here.
 */
final readonly class TitleRefreshed
{
    public function __construct(
        public int $itemId,
    ) {}
}
