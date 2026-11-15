<?php

namespace App\Integrations\Import\Data;

use App\Enums\MediaUserStatus;
use Carbon\CarbonImmutable;

/**
 * An open attempt — on hold or in progress — with its counter.
 */
final readonly class ImportStatus
{
    public function __construct(
        public MediaUserStatus $status,
        public ?CarbonImmutable $at = null,
        public ?int $progress = null,
    ) {}
}
