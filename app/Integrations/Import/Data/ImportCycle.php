<?php

namespace App\Integrations\Import\Data;

use Carbon\CarbonImmutable;

/**
 * A later run through a show as the source numbers it (2 and up) and when
 * it began — a run may begin before its first viewing, or hold none yet.
 */
final readonly class ImportCycle
{
    public function __construct(
        public int $number,
        public CarbonImmutable $startedAt,
    ) {}
}
