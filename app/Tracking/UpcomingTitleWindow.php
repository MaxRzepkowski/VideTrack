<?php

namespace App\Tracking;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaTypeRegistry;
use App\Models\MediaItem;

/**
 * Which of a followed creator's titles still count as upcoming. One rule for
 * people and companies alike, so following a director and following the
 * studio behind the same film announce the same moments.
 *
 * A film is not done the day it opens. The day it becomes watchable outside
 * a cinema is a second event worth following, and measured over real
 * titles it lands one to two months after the premiere, rarely past four. Credits carry
 * only the premiere date, so a film that opened recently stays in scope; its
 * home date is known once the title is persisted, and isSpent() then drops
 * the ones already out at home.
 */
final readonly class UpcomingTitleWindow
{
    /** How long after its premiere a film still counts as upcoming; past the usual longest gap. */
    private const HOME_RELEASE_GRACE_DAYS = 180;

    public function __construct(private MediaTypeRegistry $types) {}

    /** A dated title still ahead, or a film that opened within the grace window. */
    public function admits(MediaSummary $media): bool
    {
        if ($media->releaseDate === null) {
            return false;
        }

        $graceFloor = today()->subDays(self::HOME_RELEASE_GRACE_DAYS)->toDateString();

        return $media->releaseDate > today()->toDateString()
            || ($this->comesHomeLater($media->type) && $media->releaseDate > $graceFloor);
    }

    private function comesHomeLater(string $type): bool
    {
        return $this->types->has($type) && $this->types->get($type)->releasesWhole();
    }

    /**
     * Order before a per-creator cap bites: what is still ahead first
     * (soonest first), then films that just opened, most recent first, since
     * their home release is the furthest away and still worth announcing.
     */
    public function compare(MediaSummary $a, MediaSummary $b): int
    {
        $today = today()->toDateString();
        $aAhead = $a->releaseDate > $today;
        $bAhead = $b->releaseDate > $today;

        if ($aAhead !== $bAhead) {
            return $aAhead ? -1 : 1;
        }

        return $aAhead
            ? $a->releaseDate <=> $b->releaseDate
            : $b->releaseDate <=> $a->releaseDate;
    }

    /**
     * A credit admitted only for its home release that, now persisted, has
     * nothing left to count down to. A credit still dated ahead is never
     * second-guessed here. Anything without a home release date (every
     * non-film type, and films without such a date) is judged on its
     * premiere alone.
     */
    public function isSpent(MediaSummary $credit, MediaItem $item): bool
    {
        $today = today();

        return $credit->releaseDate !== null
            && $credit->releaseDate <= $today->toDateString()
            && $item->release_date !== null
            && $item->release_date->lte($today)
            && ($item->home_release_date === null || $item->home_release_date->lte($today));
    }
}
