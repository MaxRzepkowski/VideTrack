<?php

namespace App\Actions\Media;

use App\Catalog\Contracts\SupportsEvents;
use App\Catalog\Events\EventStore;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderRegistry;
use App\Http\Data\EventPage;
use App\Models\User;
use App\Tracking\Data\EventEntry;
use App\Tracking\Events\VisibleEvents;

/**
 * An event's page: from the events kept locally, or from its provider for
 * one long past (a game's page links every event it was shown at). Only a
 * kept event is on the calendar to be taken off it.
 */
final readonly class BuildEventPage
{
    public function __construct(
        private EventStore $events,
        private ProviderRegistry $providers,
        private VisibleEvents $visible,
    ) {}

    /** @throws ItemNotFoundException when neither the kept events nor the provider know it */
    public function execute(User $viewer, string $provider, string $ref): EventPage
    {
        $event = $this->events->find($provider, $ref);

        if ($event !== null) {
            return new EventPage(
                event: EventEntry::of($event),
                description: $event->description,
                timeZone: $event->time_zone,
                hidden: $this->visible->isHidden($viewer, $event),
                titles: $this->events->titlesOf($event),
            );
        }

        $source = $this->providers->find($provider);

        if (! $source instanceof SupportsEvents) {
            throw new ItemNotFoundException("No provider [{$provider}] lists events.");
        }

        $data = $source->event($ref);

        return new EventPage(
            event: EventEntry::fromData($provider, $data),
            description: $data->description,
            timeZone: $data->timeZone,
            hidden: null,
            titles: $data->titles,
        );
    }
}
