<?php

namespace App\Tracking\Releases;

use App\Catalog\Data\MediaSummary;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Enums\ReleaseKind;
use App\Models\MediaItem;
use Illuminate\Support\Collection;

/**
 * @phpstan-import-type CardExtra from MediaSummary
 *
 * A film's releases are its premiere, the day it left cinemas, or both — the
 * viewer's mode decides, and each date has to land inside the window on its
 * own.
 *
 * Each carries its ReleaseKind, which the dedupe key reads: without it the two
 * would collapse into one, since they describe the same title. The cards read
 * it too, to say which of the two moments they are announcing.
 *
 * A day-and-date release (both dates on ONE day — a film that opens and
 * streams the same evening) is a third kind, `both`: one entry announcing the
 * two moments together. Two cards stacked on a single day would read as the
 * calendar duplicating the title, and in the iCal feed they would share a uid,
 * so a subscribed app would keep only one of them anyway.
 */
final readonly class FilmReleases implements ReleaseSource
{
    public function __construct(private MediaItemSummaries $cards) {}

    public function releases(Collection $roots, ReleaseWindow $window): Collection
    {
        return $roots->flatMap(fn (MediaItem $film): array => $this->entries($film, $window))->values();
    }

    /**
     * @return list<ReleaseEntry>
     */
    private function entries(MediaItem $film, ReleaseWindow $window): array
    {
        $premiere = $window->mode->showsPremiere() && $film->release_date !== null && $window->contains($film->release_date->toDateString());
        $home = $window->mode->showsDigital() && $film->home_release_date !== null && $window->contains($film->home_release_date->toDateString());

        $sameDay = $film->release_date !== null
            && $film->home_release_date !== null
            && $film->release_date->isSameDay($film->home_release_date);

        if ($premiere && $home && $sameDay) {
            return [$this->entry($film, $window, ReleaseKind::Both)];
        }

        $entries = [];

        if ($premiere) {
            $entries[] = $this->entry($film, $window, ReleaseKind::Premiere);
        }

        if ($home) {
            $note = ItemMetadata::fromStored($film->metadata)->homeReleaseNote();

            $entries[] = $this->entry(
                $film,
                $window,
                ReleaseKind::Home,
                // TMDB's free-text note on the winning release row — the only
                // place it says "Netflix" rather than just a date. Absent far
                // more often than present; the card stays quiet then rather
                // than guessing a platform.
                $note === null ? [] : ['releaseNote' => $note],
                // `$home` is only true with a date in the window, so it is set.
                $film->home_release_date->toDateString(),
            );
        }

        return $entries;
    }

    /**
     * @param  CardExtra  $extra
     */
    private function entry(MediaItem $film, ReleaseWindow $window, ReleaseKind $kind, array $extra = [], ?string $date = null): ReleaseEntry
    {
        return new ReleaseEntry(
            media: $this->cards->inContext($film, [...$window->followedViaOf($film), ...$extra], $date, $kind),
            rootId: $film->id,
            rootType: $film->type,
        );
    }
}
