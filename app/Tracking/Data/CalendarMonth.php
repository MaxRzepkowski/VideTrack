<?php

namespace App\Tracking\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The calendar page's month: `month` is its 1st, `start` and `end` its first
 * and last day. `days` carries only the days that hold something; the view
 * fills in the empty ones itself.
 */
#[TypeScript]
final readonly class CalendarMonth
{
    /**
     * @param  list<CalendarDay>  $days
     */
    public function __construct(
        public string $month,
        public string $start,
        public string $end,
        public array $days,
    ) {}
}
