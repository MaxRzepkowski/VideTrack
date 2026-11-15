<?php

namespace App\Tracking\Data;

use App\Tracking\Releases\ReleaseEntry;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One calendar date and what is released on it, in reading order, with the
 * events (showcases) starting on it in the viewer's zone.
 */
#[TypeScript]
final readonly class CalendarDay
{
    /**
     * @param  string  $date  a calendar date (YYYY-MM-DD)
     * @param  list<ReleaseEntry>  $entries
     * @param  list<CalendarEvent>  $events  soonest first
     */
    public function __construct(
        public string $date,
        public array $entries,
        public array $events = [],
    ) {}
}
