<?php

namespace App\Integrations\Import\Rows;

use App\Integrations\Import\Data\ImportListRef;
use Carbon\CarbonImmutable;

/**
 * A person on a custom list, restored from the input alone — no provider
 * round-trip; the person page refresh fills in the rest later.
 */
final readonly class PersonListRow implements ImportRow
{
    public function __construct(
        public ImportListRef $list,
        public string $provider,
        public string $ref,
        public string $name,
        public ?CarbonImmutable $addedAt = null,
    ) {}
}
