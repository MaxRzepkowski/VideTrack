<?php

namespace App\Actions\Media;

use App\Accounts\UserCalendar;
use App\Catalog\Data\TypeCount;
use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\CountdownSort;
use App\Enums\SortDirection;
use App\Models\User;
use App\Support\PageInfo;
use App\Tracking\Data\CountdownEntry;
use App\Tracking\Data\CountdownFilters;
use App\Tracking\Data\EventCountdown;
use App\Tracking\Events\VisibleEvents;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;

/**
 * The countdowns page: every upcoming release the user cares about, flattened
 * and sorted by the soonest premiere, reaching far into the future (unlike the
 * dashboard/calendar's one-year window), from the countdowns column of the
 * tracking matrix.
 *
 * The events on the user's calendar count down among them, an option of
 * their own in the type filter, and stay until the show is over.
 *
 * A weekly show contributes only its NEXT episode: episode entries are deduped
 * per show, keeping the soonest. The set is small and already in memory, so
 * search, sort and paging run here rather than as another query.
 */
final readonly class BuildCountdowns
{
    /** Ten years — countdowns to distant game / movie premieres are the point. */
    public const HORIZON_DAYS = 3653;

    /** Hero cards are large; a dozen fills a page without a wall of scrolling. */
    private const PER_PAGE = 12;

    public function __construct(
        private ReleaseFeed $feed,
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private VisibleEvents $events,
    ) {}

    /**
     * @return array{entries: list<CountdownEntry|EventCountdown>, types: list<TypeCount>, filters: array<string, string|null>, pagination: PageInfo}
     */
    public function execute(User $user, CountdownFilters $filters, int $page): array
    {
        $all = $this->rows($user);

        // Counted before the search, so the numbers describe the whole set;
        // only the types actually counting down.
        $counts = [];

        foreach ($all as $row) {
            $counts[$row['type']] = ($counts[$row['type']] ?? 0) + 1;
        }

        // "All" unless the requested type is a real, present option.
        $type = $filters->type !== null && isset($counts[$filters->type])
            && ($this->types->has($filters->type) || $filters->type === EventCountdown::FILTER_TYPE) ? $filters->type : null;

        $filtered = array_values(array_filter(
            $all,
            fn (array $row) => ($type === null || $row['type'] === $type)
                && ($filters->q === null || $this->matches($row['names'], $filters->q)),
        ));

        // The list arrives soonest-first, so only the other axis re-sorts.
        if ($filters->sort === CountdownSort::Title) {
            usort($filtered, fn (array $a, array $b) => strcasecmp($a['title'], $b['title']));
        }

        if ($filters->dir === SortDirection::Desc) {
            $filtered = array_reverse($filtered);
        }

        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($lastPage, $page);

        $types = TypeCount::inOrder($counts, $this->types);

        // Events after the media types, as an option of their own.
        if (isset($counts[EventCountdown::FILTER_TYPE])) {
            $types[] = new TypeCount(EventCountdown::FILTER_TYPE, $counts[EventCountdown::FILTER_TYPE]);
        }

        return [
            'entries' => array_map(
                fn (array $row): CountdownEntry|EventCountdown => $row['card'],
                array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            ),
            'types' => $types,
            'filters' => [
                'type' => $type,
                'q' => $filters->q,
                'sort' => $filters->sort->value,
                'dir' => $filters->dir->value,
            ],
            'pagination' => new PageInfo($page, $lastPage, $total),
        ];
    }

    /**
     * The premieres and the events in one soonest-first list, each with what
     * the filters read: its type option, the title it is sorted by, the names a search hits.
     *
     * @return list<array{type: string, title: string, names: list<string|null>, card: CountdownEntry|EventCountdown}>
     */
    private function rows(User $user): array
    {
        $rows = [];

        foreach ($this->countdowns($user) as $entry) {
            $rows[] = [
                'key' => $this->sortKey($entry),
                'type' => $entry->rootType,
                'title' => $this->titleOf($entry),
                // A search hits the show's title as well as the episode's own.
                'names' => [$entry->showTitle, $entry->media->title],
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

        return array_map(fn (array $row): array => ['type' => $row['type'], 'title' => $row['title'], 'names' => $row['names'], 'card' => $row['card']], $rows);
    }

    /**
     * The full, sorted countdown list; the root type (show / movie / game / …)
     * is what the type filter reads.
     *
     * @return list<ReleaseEntry>
     */
    private function countdowns(User $user): array
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

    /** @param  list<string|null>  $names */
    private function matches(array $names, string $query): bool
    {
        foreach ($names as $name) {
            if ($name !== null && mb_stripos($name, $query) !== false) {
                return true;
            }
        }

        return false;
    }

    private function titleOf(ReleaseEntry $entry): string
    {
        return $entry->showTitle ?? $entry->media->title;
    }
}
