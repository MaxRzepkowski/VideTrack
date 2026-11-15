<?php

namespace App\Integrations\Import\Data;

use Carbon\CarbonImmutable;

/**
 * An abandoned attempt, with the counter sunk into it.
 */
final readonly class ImportDrop
{
    public function __construct(
        public ?CarbonImmutable $at = null,
        public ?int $amount = null,
    ) {}
}
