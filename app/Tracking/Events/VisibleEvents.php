<?php

namespace App\Tracking\Events;

use App\Accounts\Preferences\PreferenceKeys;
use App\Catalog\Contracts\SupportsEvents;
use App\Catalog\Events\EventStore;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Models\CatalogEvent;
use App\Models\HiddenEvent;
use App\Models\User;
use App\Support\StoredTime;
use App\Tracking\Settings\DeclaresTypeOptions;
use App\Tracking\Settings\TypeOption;
use App\Tracking\Surface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The events a user sees: every one their providers list, on the views the
 * user lets them onto, the ones they took off left out, and those of a
 * provider serving no medium here (nothing to mark them by). One rule for the
 * calendar, its iCal feed, the countdowns and the "live" button.
 */
final readonly class VisibleEvents implements DeclaresTypeOptions
{
    public function __construct(
        private EventStore $events,
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    /** Whether the views show events at all, for the medium whose providers list them. */
    public function typeOptions(MediaTypeDefinition $type): array
    {
        $listing = array_filter(
            array_keys($this->providers->allSupporting(SupportsEvents::class)),
            fn (string $provider): bool => $this->types->rootServedBy($provider) === $type->code,
        );

        if ($listing === []) {
            return [];
        }

        return [TypeOption::on(
            key: 'events',
            label: 'settings.events_and_showcases',
            description: 'settings.presentations_where_new_titles_are_announced',
            phrase: 'settings.phrase_events',
            surfaces: Surface::views(),
            preference: PreferenceKeys::events(...),
            ofTrackedTitles: false,
        )];
    }

    /**
     * Those starting within a span of instants, soonest first, whatever zone
     * the caller told them in — on the calendar.
     *
     * @return Collection<int, CatalogEvent>
     */
    public function between(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        if (! $user->preferences->showsEvents(Surface::Calendar)) {
            return new Collection;
        }

        return $this->served($this->of($user)->whereBetween('starts_at', [StoredTime::of($from), StoredTime::of($to)])->orderBy('starts_at')->get());
    }

    /**
     * Those running now or starting within the lookahead — what the "live"
     * button switches itself on and off for without asking again. It leads
     * to the event, so it is there while either view shows events.
     *
     * @return Collection<int, CatalogEvent>
     */
    public function liveOrSoon(User $user, CarbonInterface $now): Collection
    {
        if (array_filter(Surface::views(), $user->preferences->showsEvents(...)) === []) {
            return new Collection;
        }

        return $this->served($this->notOverAt($user, $now)
            ->where('starts_at', '<=', StoredTime::of($now)->addHours(config()->integer('tracker.events.live_lookahead_hours')))
            ->orderBy('starts_at')
            ->get());
    }

    /**
     * Those ahead and those on the air: a countdown to an event stays until
     * the show is over, not only until it starts — on the countdowns.
     *
     * @return Collection<int, CatalogEvent>
     */
    public function ahead(User $user, CarbonInterface $now): Collection
    {
        if (! $user->preferences->showsEvents(Surface::Countdowns)) {
            return new Collection;
        }

        return $this->served($this->notOverAt($user, $now)->orderBy('starts_at')->get());
    }

    /** The medium a visible event shows (a showcase, games), the colour it is worn in. */
    public function mediumOf(CatalogEvent $event): string
    {
        return $this->types->rootServedBy($event->provider) ?? throw new LogicException("No medium is served by [{$event->provider}]; the event is not visible.");
    }

    public function isHidden(User $user, CatalogEvent $event): bool
    {
        return HiddenEvent::query()->where('user_id', $user->id)->where('catalog_event_id', $event->id)->exists();
    }

    /**
     * Over is judged by the end, the usual length of a show standing in for a missing one.
     *
     * @return Builder<CatalogEvent>
     */
    private function notOverAt(User $user, CarbonInterface $now): Builder
    {
        $fallback = config()->integer('tracker.events.fallback_minutes');
        $stored = StoredTime::of($now);

        return $this->of($user)->where(fn (Builder $query) => $query
            ->where('ends_at', '>', $stored)
            ->orWhere(fn (Builder $open) => $open->whereNull('ends_at')->where('starts_at', '>', $stored->subMinutes($fallback))));
    }

    /**
     * A handful a month: read and then narrowed, not joined against the registry.
     *
     * @param  Collection<int, CatalogEvent>  $events
     * @return Collection<int, CatalogEvent>
     */
    private function served(Collection $events): Collection
    {
        return $events->filter(fn (CatalogEvent $event): bool => $this->types->rootServedBy($event->provider) !== null)->values();
    }

    /** @return Builder<CatalogEvent> */
    private function of(User $user): Builder
    {
        return $this->events->kept()->whereNotIn('id', HiddenEvent::query()->where('user_id', $user->id)->select('catalog_event_id'));
    }
}
