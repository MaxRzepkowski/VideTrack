<?php

namespace App\Http\Data;

use App\Catalog\Data\MediaSummary;
use App\Tracking\Data\EventEntry;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** An event's page: when and where it streams, what it showed, and whether it is off the viewer's calendar. */
#[TypeScript]
final readonly class EventPage
{
    /**
     * @param  list<MediaSummary>  $titles  what it showed, as its provider lists them
     */
    public function __construct(
        public EventEntry $event,
        public ?string $description,
        /** The zone its provider times it in, for the line under the viewer's own time. */
        public ?string $timeZone,
        /** Whether the viewer took it off their calendar; null for an event not on it (one long past, read from its provider). */
        public ?bool $hidden,
        public array $titles,
    ) {}
}
