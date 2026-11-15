<?php

namespace App\Actions\Media;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsEvents;
use App\Catalog\Data\EventData;
use App\Catalog\ProviderCalls;
use App\Tracking\Data\EventEntry;

/**
 * Every event that showed a title (a game at Summer Game Fest), long past
 * ones too, so only its provider knows them. A provider that cannot be asked
 * leaves the title's page without the section, not without the page.
 */
final readonly class ResolveEventsShowing
{
    /** @return list<EventEntry> the latest first */
    public function execute(MetadataProvider&SupportsEvents $provider, string $ref): array
    {
        $events = (new ProviderCalls)->attempt(fn (): array => $provider->eventsShowing($ref), []);

        return array_map(fn (EventData $event): EventEntry => EventEntry::fromData($provider->code(), $event), $events);
    }
}
