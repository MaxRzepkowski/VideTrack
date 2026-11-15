<?php

namespace App\Integrations\Import\Rows;

use App\Catalog\Creators\CreatorKind;
use App\Integrations\Import\Data\ImportListRef;
use Carbon\CarbonImmutable;

/**
 * A studio, publisher or network on a custom list, restored from the input
 * alone; $kind tells a company from a network.
 */
final readonly class CompanyListRow implements ImportRow
{
    public function __construct(
        public ImportListRef $list,
        public string $provider,
        public string $ref,
        public CreatorKind $kind,
        public string $name,
        public ?CarbonImmutable $addedAt = null,
    ) {}
}
