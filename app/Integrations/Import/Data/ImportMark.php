<?php

namespace App\Integrations\Import\Data;

use Carbon\CarbonImmutable;

/**
 * "On the watchlist" / "a favorite", with when it happened when known.
 */
final readonly class ImportMark
{
    public function __construct(public ?CarbonImmutable $at = null) {}
}
