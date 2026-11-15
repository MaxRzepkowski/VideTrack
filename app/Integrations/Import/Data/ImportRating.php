<?php

namespace App\Integrations\Import\Data;

use Carbon\CarbonImmutable;

/**
 * A rating already on our 1–10 scale — every source normalizes its own.
 */
final readonly class ImportRating
{
    public function __construct(
        public int $value,
        public ?CarbonImmutable $at = null,
    ) {}
}
