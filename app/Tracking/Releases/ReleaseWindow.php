<?php

namespace App\Tracking\Releases;

use App\Catalog\DatePrecision;
use App\Catalog\ReleaseRegion;
use App\Models\MediaItem;
use App\Models\User;
use App\Tracking\Surface;

/**
 * What a surface asks of the release sources: whose releases, between which
 * days, and on which surface — each source reads its own settings for it
 * from the viewer's preferences.
 */
final readonly class ReleaseWindow
{
    /**
     * @param  array<int, string>  $followedVia  root id => the followed creators backing it, by name
     */
    public function __construct(
        public User $viewer,
        public string $from,
        public string $to,
        public Surface $surface,
        /** Whether a release known only to its month, quarter or year belongs here. */
        public bool $approximateDates,
        /** Where the viewer is, as a game's regional release dates name places. */
        public ReleaseRegion $region,
        public array $followedVia = [],
    ) {}

    /**
     * The card's "because you follow X, Y", when creators back the title.
     *
     * @return array{followedVia?: string}
     */
    public function followedViaOf(MediaItem $root): array
    {
        return isset($this->followedVia[$root->id]) ? ['followedVia' => $this->followedVia[$root->id]] : [];
    }

    /**
     * The window over a stored date column: a date is stored midnight-stamped
     * (Y-m-d 00:00:00), so a bare Y-m-d upper bound would drop the last day.
     *
     * @return array{string, string}
     */
    public function storedBounds(): array
    {
        return [$this->from, "{$this->to} 23:59:59"];
    }

    /** Whether a calendar date (Y-m-d) falls inside the window. */
    public function contains(string $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }

    /**
     * Whether a release dated this way belongs in the window: an exact day
     * when it falls inside; an approximate one only where the surface takes
     * those, and while any day it may mean is still inside ("March" stays
     * ahead until March is over, whichever day the provider stamped).
     */
    public function holds(string $date, DatePrecision $precision): bool
    {
        if ($precision === DatePrecision::Day) {
            return $this->contains($date);
        }

        return $this->approximateDates && $precision->isStillAhead($date, $this->from) && $date <= $this->to;
    }
}
