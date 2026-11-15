<?php

namespace App\Library;

use App\Exceptions\NoReleaseDate;
use App\Models\MediaItem;
use Carbon\CarbonImmutable;

/**
 * When a play or a drop happened, as the user or the source said it: at a
 * moment, or on the item's own release date. A cascade over a show stamps
 * every episode with its own release date, so the date is read per item.
 */
final readonly class WatchedAt
{
    private function __construct(
        private ?CarbonImmutable $moment,
        /** Without a moment: whether an item never released falls back to now instead of being refused. */
        private bool $releaseOrNow,
    ) {}

    public static function at(CarbonImmutable $moment): self
    {
        return new self($moment, false);
    }

    public static function now(): self
    {
        return new self(CarbonImmutable::now(), false);
    }

    /** "On its release date", asked for explicitly: an item without one cannot be stamped so. */
    public static function releaseDate(): self
    {
        return new self(null, false);
    }

    /** The release date where there is one, else now: a drop "on release", and a source that gave no date. */
    public static function releaseDateOrNow(): self
    {
        return new self(null, true);
    }

    public function of(MediaItem $item): CarbonImmutable
    {
        return $this->moment
            ?? $item->release_date?->toImmutable()
            ?? ($this->releaseOrNow ? CarbonImmutable::now() : throw new NoReleaseDate("Item [{$item->id}] has no release date to stamp."));
    }
}
