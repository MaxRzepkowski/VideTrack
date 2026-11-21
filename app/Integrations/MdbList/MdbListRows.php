<?php

namespace App\Integrations\MdbList;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Integrations\EpisodeLabel;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\TitleLabel;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * MDBList's answers as import rows. MDBList speaks TMDB: a movie or a show
 * carries `ids.tmdb`, an episode its show's and TMDB's season and number.
 * Without a TMDB id the other ids (IMDb, TVDB) go to the resolver; nothing
 * is matched by title.
 *
 * Sync entries nest their title under its kind (`movie`, `show`, `season`,
 * `episode`; an episode or a season holds its show); a list item is the
 * title itself, with its kind in `mediatype`.
 */
final class MdbListRows
{
    /**
     * One viewing from `/sync/watched?plays=all`: movies and episodes, one
     * row a play, dated.
     *
     * @param  array<array-key, mixed>  $entry
     */
    public function play(MdbListGroup $group, array $entry): MediaRow|SkipRow
    {
        $at = Payload::instant($entry['watched_at'] ?? null);
        $subject = $this->subject($group, $entry);

        return match (true) {
            $subject === null => $this->unplaced($group, $entry),
            $at === null => new SkipRow('import.skip_no_watched_at', ['label' => $subject->label]),
            default => new MediaRow($subject, plays: [new ImportPlay($at)]),
        };
    }

    /**
     * One rating from `/sync/ratings`, on MDBList's 1–10 scale rounded to a
     * whole point; whether it fits ours is the runner's to say.
     *
     * @param  array<array-key, mixed>  $entry
     */
    public function rating(MdbListGroup $group, array $entry): MediaRow|SkipRow
    {
        $subject = $this->subject($group, $entry);
        $rating = Payload::int($entry, 'rating');

        if ($subject === null) {
            return $this->unplaced($group, $entry);
        }

        return $rating === null
            ? new SkipRow('import.skip_mdblist_no_rating', ['label' => $subject->label])
            : new MediaRow($subject, rating: new ImportRating($rating, Payload::instant($entry['rated_at'] ?? null)));
    }

    /**
     * A show dropped on MDBList, dropped here on the day of its last
     * viewing, when this run read one.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  array<string, CarbonImmutable>  $lastWatched  a show's TMDB id => its latest viewing
     */
    public function dropped(array $entry, array $lastWatched): MediaRow|SkipRow
    {
        // The reference leaves the shape of a dropped show open: nested
        // under `show` like the other sync entries, or flat like a list item.
        $show = isset($entry['show']) ? Payload::map($entry['show']) : $entry;
        $label = $this->label($show);
        $subject = $this->title(MediaKind::Show, $show, $label);
        $tmdb = $this->ids($show)['tmdb'] ?? null;

        return $subject === null
            ? new SkipRow('import.skip_no_id_to_look_up', ['label' => $label])
            : new MediaRow($subject, drop: new ImportDrop($tmdb === null ? null : ($lastWatched[$tmdb] ?? null)));
    }

    /**
     * A title on the watchlist, or on one of the user's lists when $list
     * names it. MDBList does not say when it was added.
     *
     * @param  array<array-key, mixed>  $item
     */
    public function listed(array $item, ?ImportListRef $list): MediaRow|SkipRow|null
    {
        $type = Payload::enum($item, 'mediatype', MdbListMediaType::class);

        if ($type === null) {
            return null;
        }

        $label = $this->label($item);
        $subject = $this->title($type->kind(), $item, $label);

        if ($subject === null) {
            return new SkipRow('import.skip_no_id_to_look_up', ['label' => $label]);
        }

        return $list === null
            ? new MediaRow($subject, watchlist: new ImportMark)
            : new MediaRow($subject, lists: [new ImportListEntry($list)]);
    }

    /**
     * The TMDB id of the show a sync entry is about, to tell a dropped show
     * by its last viewing.
     *
     * @param  array<array-key, mixed>  $entry
     */
    public function showTmdbId(MdbListGroup $group, array $entry): ?string
    {
        return Payload::id(data_get($this->show($group, $entry), 'ids.tmdb'));
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function subject(MdbListGroup $group, array $entry): ?MediaSubject
    {
        return match ($group) {
            MdbListGroup::Movies => $this->title(MediaKind::Movie, Payload::map($entry['movie'] ?? null), $this->label(Payload::map($entry['movie'] ?? null))),
            MdbListGroup::Shows => $this->title(MediaKind::Show, Payload::map($entry['show'] ?? null), $this->label(Payload::map($entry['show'] ?? null))),
            MdbListGroup::Seasons => $this->below($group, $entry, [Payload::int(Payload::map($entry['season'] ?? null), 'number')]),
            MdbListGroup::Episodes => $this->below($group, $entry, [
                Payload::int(Payload::map($entry['episode'] ?? null), 'season'),
                Payload::int(Payload::map($entry['episode'] ?? null), 'number'),
            ]),
        };
    }

    /**
     * A movie or a show: by its TMDB id, else by IMDb and TVDB.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function title(MediaKind $kind, array $object, string $label): ?MediaSubject
    {
        $ids = $this->ids($object);
        $tmdb = $ids['tmdb'] ?? null;

        if ($tmdb !== null) {
            return MediaSubject::direct($kind->value, 'tmdb', $tmdb, $label);
        }

        $foreign = ExternalIdSource::only($ids, ExternalIdSource::Imdb, ExternalIdSource::Tvdb);

        return $foreign === [] ? null : MediaSubject::external($kind, $foreign, $label);
    }

    /**
     * A season or an episode, by its show and TMDB's numbers.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  list<int|null>  $path
     */
    private function below(MdbListGroup $group, array $entry, array $path): ?MediaSubject
    {
        $show = $this->show($group, $entry);
        $ids = $this->ids($show);
        $numbers = array_values(array_filter($path, is_int(...)));

        if (count($numbers) !== count($path)) {
            return null;
        }

        $label = EpisodeLabel::of($this->label($show), $numbers[0], $numbers[1] ?? null);

        if (isset($ids['tmdb'])) {
            return MediaSubject::below(MediaKind::Show->value, 'tmdb', $ids['tmdb'], $numbers, $label);
        }

        $series = ExternalIdSource::only($ids, ExternalIdSource::Imdb, ExternalIdSource::Tvdb);
        $own = $group === MdbListGroup::Episodes
            ? ExternalIdSource::only($this->ids(Payload::map($entry['episode'] ?? null)), ExternalIdSource::Tvdb)
            : [];

        return $series === [] && $own === []
            ? null
            : MediaSubject::external($group === MdbListGroup::Episodes ? MediaKind::Episode : MediaKind::Season, $own, $label, $series, $numbers[0], $numbers[1] ?? null);
    }

    /**
     * The show a sync entry hangs off: its own for a show, the one inside a
     * season or an episode (or beside it) for those.
     *
     * @param  array<array-key, mixed>  $entry
     * @return array<array-key, mixed>
     */
    private function show(MdbListGroup $group, array $entry): array
    {
        return match ($group) {
            MdbListGroup::Movies => [],
            MdbListGroup::Shows => Payload::map($entry['show'] ?? null),
            MdbListGroup::Seasons => Payload::map(data_get($entry, 'season.show') ?? $entry['show'] ?? null),
            MdbListGroup::Episodes => Payload::map(data_get($entry, 'episode.show') ?? $entry['show'] ?? null),
        };
    }

    /**
     * An object's ids: its `ids` bag, with a list item's flat `imdb_id`,
     * `tvdb_id` and its `id` — TMDB's, which a list item's show carries
     * without a bag.
     *
     * @param  array<array-key, mixed>  $object
     * @return array<string, string>
     */
    private function ids(array $object): array
    {
        $ids = Payload::ids($object['ids'] ?? null);
        $flat = array_filter([
            'tmdb' => isset($object['mediatype']) ? Payload::id($object['id'] ?? null) : null,
            ExternalIdSource::Imdb->value => Payload::id($object['imdb_id'] ?? null),
            ExternalIdSource::Tvdb->value => Payload::id($object['tvdb_id'] ?? null),
        ], fn (?string $id): bool => $id !== null);

        return [...$flat, ...$ids];
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function unplaced(MdbListGroup $group, array $entry): SkipRow
    {
        $object = $group === MdbListGroup::Movies ? Payload::map($entry['movie'] ?? null) : $this->show($group, $entry);

        return new SkipRow('import.skip_no_id_to_look_up', ['label' => $this->label($object)]);
    }

    /**
     * @param  array<array-key, mixed>  $object
     */
    private function label(array $object): string
    {
        $title = Payload::string($object, 'title') ?? __('import.untitled_entry');
        $year = Payload::int($object, 'year') ?? Payload::int($object, 'release_year');

        return TitleLabel::of($title, $year);
    }
}
