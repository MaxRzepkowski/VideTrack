<?php

namespace App\Integrations\Import\Data;

use Carbon\CarbonImmutable;

/**
 * A title's place on a list, with when it was added.
 */
final readonly class ImportListEntry
{
    public function __construct(
        public ImportListRef $list,
        public ?CarbonImmutable $addedAt = null,
    ) {}
}
