<?php

namespace App\Integrations\Import\Rows;

use App\Catalog\Creators\CreatorRef;
use App\Enums\FollowMode;

/**
 * The bell on a person, a company or a collection. A person or a company
 * restores from the input alone (its page fills in the rest later); a
 * collection has to be read from its provider, which holds its parts.
 */
final readonly class CreatorFollowRow implements ImportRow
{
    public function __construct(
        public CreatorRef $creator,
        public string $name,
        public FollowMode $mode,
    ) {}
}
