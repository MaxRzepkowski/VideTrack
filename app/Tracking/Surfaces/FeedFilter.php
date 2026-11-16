<?php

namespace App\Tracking\Surfaces;

use App\Enums\ReleaseKind;
use App\Tracking\Data\CalendarDay;
use App\Tracking\Data\CalendarEvent;
use App\Tracking\Releases\ReleaseEntry;

/**
 * The variant a subscription address asks for, read the same by every feed.
 * It only narrows what the tracking matrix put on the calendar, so a release
 * is the same entry (and the same UID) in every variant that keeps it.
 */
final readonly class FeedFilter
{
    /**
     * @param  list<string>  $types  root type codes in the registry's order; none narrows nothing
     */
    public function __construct(
        public array $types = [],
        public FeedScope $scope = FeedScope::All,
    ) {}

    /**
     * The days with what the variant keeps of them; a day left with nothing is dropped.
     *
     * @param  list<CalendarDay>  $days
     * @return list<CalendarDay>
     */
    public function days(array $days): array
    {
        $kept = [];

        foreach ($days as $day) {
            $entries = array_values(array_filter($day->entries, $this->admits(...)));
            $events = array_values(array_filter($day->events, $this->admitsEvent(...)));

            if ($entries !== [] || $events !== []) {
                $kept[] = new CalendarDay($day->date, $entries, $events);
            }
        }

        return $kept;
    }

    /**
     * An episode counts as its show. In premieres a title stays on its
     * premiere (a film leaving cinemas is not one) and a show on the
     * episodes opening its seasons.
     */
    public function admits(ReleaseEntry $entry): bool
    {
        if (! $this->holdsType($entry->rootType)) {
            return false;
        }

        if ($this->scope === FeedScope::All) {
            return true;
        }

        return $entry->isPart() ? $entry->opensSeason() : $entry->media->releaseKind !== ReleaseKind::Home;
    }

    /** An event goes with the medium it shows, and is no premiere of a title. */
    public function admitsEvent(CalendarEvent $event): bool
    {
        return $this->scope === FeedScope::All && $this->holdsType($event->mediumType);
    }

    /** The variant as a cache key part: equal variants, equal keys. */
    public function key(): string
    {
        return $this->scope->value.':'.implode(',', $this->types);
    }

    private function holdsType(string $type): bool
    {
        return $this->types === [] || in_array($type, $this->types, true);
    }
}
