<?php

namespace App\Integrations\Trakt;

use App\Catalog\MediaKind;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\ManifestList;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\ImportCategory;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\PersonListRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\Payload;
use Generator;

/**
 * A Trakt.tv export (a ZIP of JSON files, {@see TraktArchive}). The user picks
 * which categories to bring over and which custom lists.
 *
 * Trakt keys items by a bag of external ids. With `ids.tmdb` the title is
 * placed directly (a season or an episode below its show, by number). An
 * entry whose bag misses tmdb is not lost: its other ids go to the import's
 * resolver, and only an entry nobody can place is skipped and tallied.
 */
final class TraktZipSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'trakt';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.trakt_tv_export_zip');
    }

    public function description(): string
    {
        return __('import.choose_what_to_bring_over_below');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_trakt_tv');
    }

    public function accept(): string
    {
        return '.zip';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.get_your_export_from_trakt_tv'), 'https://trakt.tv/settings/data'),
            new SetupNote(__('import.click_export_now_there_then_upload')),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $archive = $this->archive($input);

        if (! $archive->isValid()) {
            return ImportManifest::invalid(__('import.this_file_is_not_a_trakt'));
        }

        if ($archive->isTooLarge()) {
            return ImportManifest::invalid(__('import.a_file_in_this_export_is'));
        }

        $categories = array_map(fn (ImportCategory $category): ManifestCategory => $category->counted($archive->count($category)), ImportCategory::cases());

        return ImportManifest::selectable($categories, array_map(
            fn (array $list) => new ManifestList((string) $list['trakt_id'], $list['name'], $list['item_count'], $list['is_public']),
            $archive->lists(),
        ));
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $archive = $this->archive($input);

        if ($selection->has(ImportCategory::History->value)) {
            yield from $this->history($archive);
        }

        if ($selection->has(ImportCategory::Ratings->value)) {
            yield from $this->ratings($archive);
        }

        if ($selection->has(ImportCategory::Watchlist->value)) {
            foreach ($archive->category(ImportCategory::Watchlist) as $entry) {
                yield $this->media($entry, fn (MediaSubject $subject) => new MediaRow(
                    $subject,
                    watchlist: new ImportMark(Payload::instant($entry['listed_at'] ?? null)),
                ));
            }
        }

        if ($selection->has(ImportCategory::Favorites->value)) {
            foreach ($archive->category(ImportCategory::Favorites) as $entry) {
                yield $this->media($entry, fn (MediaSubject $subject) => new MediaRow(
                    $subject,
                    favorite: new ImportMark(Payload::instant($entry['listed_at'] ?? null)),
                ));
            }
        }

        foreach ($selection->listIds as $id) {
            yield from $this->list($archive, (int) $id);
        }
    }

    private function archive(ImportInput $input): TraktArchive
    {
        return new TraktArchive((string) $input->path);
    }

    /**
     * @return Generator<int, MediaRow|SkipRow>
     */
    private function history(TraktArchive $archive): Generator
    {
        foreach ($archive->category(ImportCategory::History) as $entry) {
            // History carries movie and episode watch events; only those are
            // playable positions.
            if (! in_array($entry['type'] ?? null, ['movie', 'episode'], true)) {
                yield new SkipRow('import.skip_trakt_not_a_viewing', ['label' => $this->entryLabel($entry)]);

                continue;
            }

            $watchedAt = Payload::instant($entry['watched_at'] ?? null);

            if ($watchedAt === null) {
                yield new SkipRow('import.skip_no_watched_at', ['label' => $this->entryLabel($entry)]);

                continue;
            }

            yield $this->media($entry, fn (MediaSubject $subject) => new MediaRow(
                $subject,
                plays: [new ImportPlay($watchedAt)],
            ));
        }
    }

    /**
     * @return Generator<int, MediaRow|SkipRow>
     */
    private function ratings(TraktArchive $archive): Generator
    {
        foreach ($archive->category(ImportCategory::Ratings) as $entry) {
            $rating = $entry['rating'] ?? null;

            if (! is_int($rating)) {
                yield new SkipRow('import.skip_trakt_no_rating', ['label' => $this->entryLabel($entry)]);

                continue;
            }

            yield $this->media($entry, fn (MediaSubject $subject) => new MediaRow(
                $subject,
                rating: new ImportRating($rating, Payload::instant($entry['rated_at'] ?? null)),
            ));
        }
    }

    /**
     * @return Generator<int, ListRow|PersonListRow|MediaRow|SkipRow>
     */
    private function list(TraktArchive $archive, int $id): Generator
    {
        $meta = $archive->listMeta($id);

        if ($meta === null) {
            return;
        }

        $list = new ImportListRef($meta['name'], isPublic: $meta['is_public']);

        yield new ListRow($list);

        // Trakt sorts list items by `rank`; restore that order so the
        // imported list reads the same way it did on Trakt.
        $items = iterator_to_array($archive->listItems($id), false);
        usort($items, fn (array $a, array $b) => ($a['rank'] ?? 0) <=> ($b['rank'] ?? 0));

        foreach ($items as $entry) {
            $addedAt = Payload::instant($entry['listed_at'] ?? null);

            if (($entry['type'] ?? null) === 'person') {
                $person = Payload::map($entry['person'] ?? null);
                $ref = $this->tmdbId($person);
                $name = Payload::string($person, 'name');

                yield $ref !== null && $name !== null
                    ? new PersonListRow($list, 'tmdb', $ref, $name, $addedAt)
                    : new SkipRow('import.skip_trakt_person_incomplete', ['label' => $this->entryLabel($entry)]);

                continue;
            }

            yield $this->media($entry, fn (MediaSubject $subject) => new MediaRow(
                $subject,
                lists: [new ImportListEntry($list, $addedAt)],
            ));
        }
    }

    /**
     * A Trakt entry placed at TMDB, handed to $build; a skip when no id in
     * its bag leads anywhere — or names no type at all.
     *
     * @param  array<array-key, mixed>  $entry
     * @param  callable(MediaSubject): MediaRow  $build
     */
    private function media(array $entry, callable $build): MediaRow|SkipRow
    {
        $type = $entry['type'] ?? null;
        $label = $this->entryLabel($entry);

        // An entry without a type is not a position; the report still says it was there.
        if (! is_string($type)) {
            return new SkipRow('import.skip_trakt_entry_without_type', ['label' => $label]);
        }

        $subject = $this->subject($type, $entry, $label);

        return $subject === null
            ? new SkipRow('import.skip_no_id_to_look_up', ['label' => $label])
            : $build($subject);
    }

    /**
     * Which title an entry is about. With the TMDB id of the movie or show it
     * hangs off, the title is placed directly — a season or an episode below
     * its show, numbered. Trakt does not promise `ids.tmdb`; without it the
     * other ids (imdb, tvdb) go to the import's resolver like every source's.
     *
     * @param  array<array-key, mixed>  $entry
     */
    private function subject(string $type, array $entry, string $label): ?MediaSubject
    {
        $root = in_array($type, ['season', 'episode'], true) ? 'show' : $type;
        $object = $entry[$root] ?? null;
        $path = match ($type) {
            'season' => [$this->number($entry['season'] ?? null, 'number')],
            'episode' => [$this->number($entry['episode'] ?? null, 'season'), $this->number($entry['episode'] ?? null, 'number')],
            'movie', 'show' => [],
            default => null,
        };

        if ($path === null || in_array(null, $path, true)) {
            return null;
        }

        /** @var list<int> $path */
        $tmdb = $this->tmdbId($object);

        if ($tmdb !== null) {
            return $path === []
                ? MediaSubject::direct($type, 'tmdb', $tmdb, $label)
                : MediaSubject::below('show', 'tmdb', $tmdb, $path, $label);
        }

        $ids = $this->ids($object);

        if ($ids === []) {
            return null;
        }

        return $path === []
            ? MediaSubject::external(MediaKind::from($type), $ids, $label)
            : MediaSubject::external(MediaKind::from($type), [], $label, $ids, $path[0], $path[1] ?? null);
    }

    /**
     * Every id in an object's bag, as strings.
     *
     * @return array<string, string>
     */
    private function ids(mixed $object): array
    {
        return Payload::ids(is_array($object) ? ($object['ids'] ?? null) : null);
    }

    private function tmdbId(mixed $object): ?string
    {
        return Payload::id(data_get($object, 'ids.tmdb'));
    }

    private function number(mixed $object, string $key): ?int
    {
        $value = is_array($object) ? ($object[$key] ?? null) : null;

        return is_int($value) ? $value : null;
    }

    /**
     * A human label for skip messages: the entry's title/year when present.
     *
     * @param  array<array-key, mixed>  $entry
     */
    private function entryLabel(array $entry): string
    {
        foreach (['movie', 'show', 'episode', 'season', 'person'] as $key) {
            $object = $entry[$key] ?? null;

            if (is_array($object) && is_string($object['title'] ?? null) && $object['title'] !== '') {
                return $object['title'];
            }
        }

        return is_string($entry['type'] ?? null) ? $entry['type'] : 'entry';
    }
}
