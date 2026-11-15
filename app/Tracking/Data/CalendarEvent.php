<?php

namespace App\Tracking\Data;

use App\Models\CatalogEvent;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An event on a calendar day, marked like the releases beside it: in the
 * colour of the medium it shows (a showcase in the games' colour).
 */
#[TypeScript]
final readonly class CalendarEvent
{
    public function __construct(
        public EventEntry $event,
        /** The media type its provider serves, for its colour. */
        public string $mediumType,
    ) {}

    public static function of(CatalogEvent $event, string $mediumType): self
    {
        return new self(EventEntry::of($event), $mediumType);
    }
}
