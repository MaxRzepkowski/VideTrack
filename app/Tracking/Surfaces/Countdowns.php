<?php

namespace App\Tracking\Surfaces;

use App\Accounts\UserCalendar;
use App\Catalog\Hierarchy;
use App\Catalog\RelatedReleaseCard;
use App\Models\User;
use App\Tracking\Data\CountdownEntry;
use App\Tracking\Data\EventCountdown;
use App\Tracking\Events\VisibleEvents;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;

/**
 * Every upcoming release the user counts down to, soonest first, reaching far
 * into the future (unlike the dashboard/calendar's one-year window), from the
 * countdowns column of the tracking matrix.
 *
 * The events on the user's calendar count down among them, a kind of their
 * own, and stay until the show is over.
 *
 * A weekly show contributes only its NEXT episode: episode entries are deduped
 * per show, keeping the soonest.
 */
final readonly class Countdowns
{
    /** Ten years — countdowns to distant game / movie premieres are the point. */
    public const HORIZON_DAYS = 3653;

    public function __construct(
        private ReleaseFeed $feed,
        private Hierarchy $hierarchy,
        private VisibleEvents $events,
    ) {}

    public function of(User $user): CountdownSet
    {
        $rows = [];

        foreach ($this->premieres($user) as $entry) {
            $rows[] = [
                'key' => $this->sortKey($entry),
                'type' => $entry->rootType,
                'title' => $entry->showTitle ?? $entry->media->title,
                // A search hits the show's title as well as the episode's own,
                // and the game's as well as its DLC's.
                'names' => [$entry->showTitle, $entry->media->title, RelatedReleaseCard::of($entry->media->extra)?->game],
                'card' => CountdownEntry::of($entry),
            ];
        }

        // An event stands on the day it starts in the viewer's zone, by its
        // hour among that day's premieres; it stays until the show is over.
        $calendar = UserCalendar::of($user);

        foreach ($this->events->ahead($user, now()) as $event) {
            $rows[] = [
                'key' => [$calendar->day($event->starts_at), $event->starts_at->toIso8601String(), $event->name, 0, 0],
                'type' => EventCountdown::FILTER_TYPE,
                'title' => $event->name,
                'names' => [$event->name],
                'card' => EventCountdown::of($event, $this->events->mediumOf($event)),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return new CountdownSet(array_map(
            fn (array $row): array => ['type' => $row['type'], 'title' => $row['title'], 'names' => $row['names'], 'card' => $row['card']],
            $rows,
        ));
    }

    /**
     * The premieres, sorted; the root type (show / movie / game / …) is what
     * the type filter reads.
     *
     * @return list<ReleaseEntry>
     */
    private function premieres(User $user): array
    {
        $today = UserCalendar::of($user)->today();
        $from = $today->toDateString();
        $to = $today->addDays(self::HORIZON_DAYS)->toDateString();

        $entries = $this->feed->entries($user, Surface::Countdowns, $from, $to)
            // A game may legitimately appear per platform / release status; the
            // key keeps those apart while dropping true duplicates. Sorted
            // first, so a film with both moments ahead keeps the nearer one.
            ->sort($this->compareSoonest(...))
            ->unique(fn (ReleaseEntry $entry) => implode(':', [
                $entry->media->type,
                $entry->media->ref,
                $entry->media->platform,
                $entry->media->releaseStatus,
            ]));

        // Seasons and episodes collapse to the soonest per show — a weekly
        // series shows only its next airing. Specials are left out,
        // matching Up Next: a countdown to a bonus/recap special must not mask
        // the show's real next episode. Sorting first, then uniquing by the
        // show, keeps that earliest one.
        $titles = $entries->reject(fn (ReleaseEntry $entry) => $entry->isPart());

        $nextPerShow = $entries
            ->filter(fn (ReleaseEntry $entry) => $entry->isPart())
            ->reject(fn (ReleaseEntry $entry) => $this->hierarchy->isSpecialsChild($entry->rootType, $entry->media->seasonNumber))
            ->sort($this->compareSoonest(...))
            ->unique(fn (ReleaseEntry $entry) => $entry->rootId);

        return array_values($titles
            ->concat($nextPerShow)
            ->sort($this->compareSoonest(...))
            ->all());
    }

    /**
     * Orders entries by the soonest premiere instant — by date, then the exact
     * airtime when known, then title / season / episode for a stable order. A
     * date known only to its year (or quarter, or month) stands after every
     * exact day it may still be: "2028" comes once 2028's known days are done.
     */
    private function compareSoonest(ReleaseEntry $a, ReleaseEntry $b): int
    {
        return $this->sortKey($a) <=> $this->sortKey($b);
    }

    /**
     * @return array{string, string, string, int, int}
     */
    private function sortKey(ReleaseEntry $entry): array
    {
        $media = $entry->media;

        return [
            $media->releasePrecision->lastDay((string) $media->releaseDate),
            (string) ($media->releaseTime ?? ''),
            $entry->showTitle ?? $media->title,
            $media->seasonNumber ?? 0,
            (int) ($media->number ?? 0),
        ];
    }
}
