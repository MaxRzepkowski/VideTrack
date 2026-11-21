<?php

namespace App\Integrations\Tmdb;

use App\Catalog\MediaKind;
use App\Integrations\EpisodeLabel;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\TitleLabel;
use App\Support\Payload;

/**
 * A TMDB account's listings as import rows. Everything there is a TMDB id
 * already, so a title is placed directly; an episode by its show's id and
 * its numbers. TMDB keeps no dates for any of it.
 */
final class TmdbAccountRows
{
    /**
     * A movie or a show of a watchlist, a favourites listing or the ratings.
     *
     * @param  array<array-key, mixed>  $item
     */
    public function title(MediaKind $kind, TmdbListing $listing, array $item): MediaRow|SkipRow|null
    {
        $subject = $this->subject($kind, $item);

        if ($subject === null) {
            return null;
        }

        return match ($listing) {
            TmdbListing::Watchlist => new MediaRow($subject, watchlist: new ImportMark),
            TmdbListing::Favorites => new MediaRow($subject, favorite: new ImportMark),
            TmdbListing::Rated => $this->rated($subject, $item),
        };
    }

    /**
     * A rated episode, below its show.
     *
     * @param  array<array-key, mixed>  $item
     */
    public function episode(array $item): MediaRow|SkipRow|null
    {
        $show = Payload::id($item['show_id'] ?? null);
        $season = Payload::int($item, 'season_number');
        $number = Payload::int($item, 'episode_number');

        if ($show === null || $season === null || $number === null) {
            return null;
        }

        // TMDB names the episode, not its show.
        $label = EpisodeLabel::untitledShow($season, $number, Payload::string($item, 'name'));

        return $this->rated(MediaSubject::below(MediaKind::Show->value, 'tmdb', $show, [$season, $number], $label), $item);
    }

    /**
     * A title on one of the user's lists, which name the kind of each item.
     *
     * @param  array<array-key, mixed>  $item
     */
    public function listed(ImportListRef $list, array $item): ?MediaRow
    {
        $kind = match (Payload::enum($item, 'media_type', TmdbMediaType::class)) {
            TmdbMediaType::Movie => MediaKind::Movie,
            TmdbMediaType::Tv => MediaKind::Show,
            null => null,
        };
        $subject = $kind === null ? null : $this->subject($kind, $item);

        return $subject === null ? null : new MediaRow($subject, lists: [new ImportListEntry($list)]);
    }

    /**
     * TMDB rates on ten points in halves; ours takes whole points, and
     * whether the value fits is the runner's to say.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function rated(MediaSubject $subject, array $item): MediaRow|SkipRow
    {
        $rating = Payload::float($item, 'rating');

        return $rating === null
            ? new SkipRow('import.skip_tmdb_no_rating', ['label' => $subject->label])
            : new MediaRow($subject, rating: new ImportRating((int) round($rating)));
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function subject(MediaKind $kind, array $item): ?MediaSubject
    {
        $id = Payload::id($item['id'] ?? null);

        if ($id === null) {
            return null;
        }

        // A movie has a title and a release date, a show a name and a first air date.
        $title = Payload::string($item, 'title') ?? Payload::string($item, 'name') ?? __('import.untitled_entry');
        $year = Payload::yearOf(Payload::string($item, 'release_date') ?? Payload::string($item, 'first_air_date'));

        return MediaSubject::direct($kind->value, 'tmdb', $id, TitleLabel::of($title, $year));
    }
}
