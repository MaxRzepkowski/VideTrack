<?php

namespace App\Tracking\Data;

use App\Models\CatalogEvent;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An event among the countdowns: its own option in the type filter, worn in
 * the colour of the medium it shows (a showcase in the games' colour).
 */
#[TypeScript]
final readonly class EventCountdown
{
    /** The type filter's option for events: no media type, a kind of countdown of its own. */
    public const FILTER_TYPE = 'event';

    public function __construct(
        public EventEntry $event,
        /** The media type its provider serves (games for a showcase), for its colour. */
        public string $mediumType,
        public string $filterType,
    ) {}

    public static function of(CatalogEvent $event, string $mediumType): self
    {
        return new self(EventEntry::of($event), $mediumType, self::FILTER_TYPE);
    }
}
