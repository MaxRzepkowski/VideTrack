<?php

namespace App\Tracking\Surfaces;

use App\Catalog\EpisodeBadge;
use App\Catalog\ImageUrls;
use App\Enums\ReleaseKind;
use App\Http\CardPages;
use App\Models\User;
use App\Support\PublicUrl;
use App\Tracking\Releases\ReleaseEntry;

/**
 * What a subscription feed says, the same in the iCal and the RSS feed: its
 * name, and of each release its name, the facts under it in the current
 * locale, the page it leads to and the identifier a subscriber keeps it by.
 */
final readonly class FeedItems
{
    /** How often a subscribed app asks for a feed again. */
    public const REFRESH_MINUTES = 12 * 60;

    public function __construct(private CardPages $pages) {}

    /** What a calendar app or a reader calls the subscription. */
    public function name(User $user): string
    {
        return __('calendar.feed_name', ['app' => config()->string('app.name'), 'name' => $user->username]);
    }

    /** "Show S01E01 – Episode" for an episode, the title itself otherwise. */
    public function title(ReleaseEntry $entry): string
    {
        $media = $entry->media;

        if ($entry->showTitle !== null) {
            $code = sprintf('S%02dE%02d', $media->seasonNumber ?? 0, $media->number ?? 0);

            return "{$entry->showTitle} {$code} – {$media->title}";
        }

        return $media->title;
    }

    /**
     * What the calendar card says beside the title, one fact per line: what
     * the episode opens or closes, which release of a film, where, why it is
     * on the calendar, and an episode's synopsis.
     *
     * @return list<string>
     */
    public function facts(ReleaseEntry $entry): array
    {
        $media = $entry->media;
        $note = $media->extra['releaseNote'] ?? null;
        $via = $media->extra['followedVia'] ?? null;

        $facts = [];

        if ($media->episodeBadge !== null) {
            $facts[] = $this->badge($media->episodeBadge);
        }

        if (in_array($media->releaseKind, [ReleaseKind::Home, ReleaseKind::Both], true)) {
            $facts[] = __('common.outside_cinemas');
        }

        foreach ([$media->platform, $media->releaseStatus, $note] as $fact) {
            if ($fact !== null && $fact !== '') {
                $facts[] = $fact;
            }
        }

        if ($via !== null && $via !== '') {
            $facts[] = __('calendar.because_you_follow_name', ['name' => $via]);
        }

        if ($media->overview !== null && $media->overview !== '') {
            $facts[] = $media->overview;
        }

        return $facts;
    }

    /** The release's own page at the instance's address: the reader opens it from another app. */
    public function url(ReleaseEntry $entry): string
    {
        return PublicUrl::to($this->pages->path($entry->media->type, $entry->media->ref));
    }

    /** The cover a reader shows beside the item: an episode's is its show's poster. */
    public function cover(ReleaseEntry $entry): ?string
    {
        $media = $entry->media;

        return ImageUrls::of($entry->isPart() ? ($media->extra['showPoster'] ?? null) : $media->posterPath, ImageUrls::POSTER);
    }

    /**
     * Stable per release so re-fetches update rather than duplicate; the
     * platform and the release status keep a game's several releases apart,
     * as the calendar page does. (A film's two releases differ by date; on
     * one day they arrive merged.) A feed's variant never enters it: the same
     * release is the same item in every subscription that carries it.
     */
    public function uid(string $date, ReleaseEntry $entry): string
    {
        $media = $entry->media;

        // The status only where there is one: every UID already handed out
        // stays what it was, or calendars would show each release twice.
        return implode('-', [
            $media->type,
            $media->ref,
            $media->platform,
            ...($media->releaseStatus === null ? [] : [$media->releaseStatus]),
            $date,
        ]).'@videtrack';
    }

    private function badge(EpisodeBadge $badge): string
    {
        return match ($badge) {
            EpisodeBadge::SeriesPremiere => __('common.series_premiere'),
            EpisodeBadge::SeasonPremiere => __('common.season_premiere'),
            EpisodeBadge::MidSeasonFinale => __('common.mid_season_finale'),
            EpisodeBadge::SeasonFinale => __('common.season_finale'),
            EpisodeBadge::SeriesFinale => __('common.series_finale'),
        };
    }
}
