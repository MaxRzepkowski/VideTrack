<?php

namespace App\Catalog\Data;

use Carbon\CarbonImmutable;

/**
 * How a provider last failed: the status it answered (none when it did not
 * answer at all), its own word for it, and when.
 */
final readonly class ProviderFailure
{
    public function __construct(
        public ?int $status,
        public ?string $reason,
        public CarbonImmutable $at,
    ) {}
}
