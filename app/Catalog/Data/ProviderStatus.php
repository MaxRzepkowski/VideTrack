<?php

namespace App\Catalog\Data;

use App\Catalog\ProviderState;
use Carbon\CarbonImmutable;

/**
 * What is known of a provider's answers: its state since when, when it last
 * answered or was last asked only to see whether it answers, and how it last
 * failed.
 */
final readonly class ProviderStatus
{
    public function __construct(
        public ProviderState $state,
        public ?CarbonImmutable $since = null,
        public ?CarbonImmutable $answeredAt = null,
        public ?CarbonImmutable $probedAt = null,
        public ?ProviderFailure $failure = null,
    ) {}
}
