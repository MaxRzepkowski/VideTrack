<?php

namespace App\Users\Statistics\Data;

use App\Users\Statistics\PeriodKind;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The period on show, with the days it actually spans for this user. */
#[TypeScript]
final readonly class PeriodSpan
{
    public function __construct(
        public PeriodKind $kind,
        public ?int $year,
        /** First day, Y-m-d; for the whole history the day of its first finished entry. Null when there is no history to start from. */
        public ?string $from,
        /** Last day, Y-m-d. */
        public ?string $to,
        /** Days from `from` up to `to` or today, whichever comes first. */
        public int $days,
    ) {}
}
