<?php

namespace App\Catalog\Contracts;

use App\Models\MediaItem;

/**
 * Broadcast times for a show's episodes, from a schedule other than the
 * show's own metadata provider. Times are keyed "season:number" and are real
 * airing slots only — a source's placeholder time for a streaming drop is
 * never reported, so the app never invents a time.
 *
 * The schedule only answers; remembering which of its shows a show is, so
 * later asks skip the lookup, is the library's business.
 */
interface SupportsAirSchedule
{
    /** The key the schedule's id for a show is remembered under. */
    public function showIdKey(): string;

    /**
     * Which of the schedule's shows this is, from the show's external ids or
     * its title and premiere year. Null when the schedule does not know it.
     */
    public function findShow(MediaItem $show): ?int;

    /**
     * @return array<string, string> season:number => ISO-8601 airstamp
     */
    public function airTimes(int $showId): array;

    /**
     * The same answer from what is already cached, never a live request —
     * for page renders. Null when nothing is cached yet for this show.
     *
     * @return array<string, string>|null season:number => ISO-8601 airstamp
     */
    public function cachedAirTimes(int $showId): ?array;
}
