<?php

namespace App\Tracking\Surfaces;

use App\Accounts\UserCalendar;
use App\Models\User;
use App\Tracking\Data\CalendarDay;
use App\Tracking\Data\CalendarEvent;
use App\Tracking\Events\VisibleEvents;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;
use Illuminate\Support\Collection;

/**
 * The release calendar as days: the dashboard's nearest few — the days that
 * actually have releases, not a fixed week that is usually empty — and the
 * calendar page's whole month. Within a day a show's episodes stay together
 * and in order.
 */
final readonly class CalendarDays
{
    public const MAX_DAYS = 5;

    public const HORIZON_DAYS = 365;

    public function __construct(
        private ReleaseFeed $feed,
        private VisibleEvents $events,
    ) {}

    /**
     * The dashboard's few days: the nearest ones that actually have releases,
     * within a year; the events stay on the calendar page.
     *
     * @return list<CalendarDay>
     */
    public function nearest(User $user): array
    {
        $today = UserCalendar::of($user)->today();
        $entries = $this->feed->entries($user, Surface::Calendar, $today->toDateString(), $today->addDays(self::HORIZON_DAYS)->toDateString());

        // Releases alone: some fifteen showcases a month would take the few
        // days the dashboard shows from the releases it is there for.
        return $this->days($entries, limit: self::MAX_DAYS);
    }

    /**
     * Every release in a window — past included — for the calendar page's
     * month and the iCal feed.
     *
     * @return list<CalendarDay>
     */
    public function range(User $user, string $from, string $to): array
    {
        return $this->days($this->feed->entries($user, Surface::Calendar, $from, $to), $this->eventsOn($user, $from, $to));
    }

    /**
     * The events starting on those days in the viewer's zone, by that day.
     *
     * @return array<string, list<CalendarEvent>>
     */
    private function eventsOn(User $user, string $from, string $to): array
    {
        $calendar = UserCalendar::of($user);
        $byDay = [];

        foreach ($this->events->between($user, $calendar->startOf($from), $calendar->endOf($to)) as $event) {
            $byDay[$calendar->day($event->starts_at)][] = CalendarEvent::of($event, $this->events->mediumOf($event));
        }

        return $byDay;
    }

    /**
     * Deduped and grouped by release date, chronologically; a day with an
     * event and nothing released is a day too.
     *
     * @param  Collection<int, ReleaseEntry>  $entries
     * @param  array<string, list<CalendarEvent>>  $events
     * @return list<CalendarDay>
     */
    private function days(Collection $entries, array $events = [], ?int $limit = null): array
    {
        $days = $entries
            // A game may legitimately appear several times (per platform /
            // release status) — the key keeps those apart.
            ->unique(fn (ReleaseEntry $entry) => implode(':', [
                $entry->media->type,
                $entry->media->ref,
                $entry->media->platform,
                $entry->media->releaseStatus,
                // A film legitimately appears twice — its premiere and the day
                // it left cinemas are two different events about one title.
                $entry->media->releaseKind?->value,
            ]))
            ->groupBy(fn (ReleaseEntry $entry) => (string) $entry->media->releaseDate);

        $dates = array_values(array_unique([...array_map('strval', $days->keys()->all()), ...array_map('strval', array_keys($events))]));
        sort($dates);

        if ($limit !== null) {
            $dates = array_slice($dates, 0, $limit);
        }

        return array_map(function (string $date) use ($days, $events): CalendarDay {
            $dayEntries = $days->get($date);

            return new CalendarDay(
                date: $date,
                entries: $dayEntries === null ? [] : array_values($dayEntries->sort($this->compareEntries(...))->all()),
                events: $events[$date] ?? [],
            );
        }, $dates);
    }

    /**
     * A show's episodes stay together and in order (show, then season, then
     * episode number) rather than interleaving shows by episode number;
     * standalone releases sort by title.
     */
    private function compareEntries(ReleaseEntry $a, ReleaseEntry $b): int
    {
        return [$a->showTitle ?? $a->media->title, $a->media->seasonNumber ?? 0, $a->media->number ?? 0]
            <=> [$b->showTitle ?? $b->media->title, $b->media->seasonNumber ?? 0, $b->media->number ?? 0];
    }
}
