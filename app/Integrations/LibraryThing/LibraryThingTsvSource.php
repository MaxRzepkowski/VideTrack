<?php

namespace App\Integrations\LibraryThing;

use App\Catalog\ExternalIdSource;
use App\Catalog\Isbn;
use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\LibraryList;
use App\Integrations\Import\OwnLists;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * A LibraryThing catalog exported as tab-delimited text (More → Import/Export
 * → Export). One row per book; what the reader did with it lives in its
 * collections: "Read but unowned" or a reading date is a read, "Currently
 * reading" in progress, "Wishlist" and "To read" the watchlist, "Favorites"
 * a favorite, "Your library" the books owned, which come over as a
 * "LibraryThing" list. A collection of the reader's own is a list to pick.
 *
 * Each of those is a category of the selection, and a book yields a row for
 * every one it falls in. Dates sit in brackets ("[2007-05-08]") and only a
 * full day is read; the author is written "Last, First". Books are placed by
 * ISBN, then by title and author.
 */
final class LibraryThingTsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'librarything';

    /** The service the list of the books owned is named after. */
    private const SERVICE = 'LibraryThing';

    /** LibraryThing's own collections, folded, by the category they make. */
    private const COLLECTIONS = [
        'your library' => LibraryThingShelf::Owned,
        'read but unowned' => LibraryThingShelf::Read,
        'read' => LibraryThingShelf::Read,
        'currently reading' => LibraryThingShelf::Reading,
        'wishlist' => LibraryThingShelf::Wanted,
        'to read' => LibraryThingShelf::Wanted,
        'favorites' => LibraryThingShelf::Favorites,
    ];

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.librarything_export_tsv');
    }

    public function description(): string
    {
        return __('import.books_in_your_librarything_catalog');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_librarything');
    }

    public function accept(): string
    {
        return '.tsv,.txt';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.on_librarything_open_more_import_export'), 'https://www.librarything.com/more/import'),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_librarything'));
        }

        $counts = array_fill_keys(array_column(LibraryThingShelf::cases(), 'value'), 0);
        $own = new OwnLists;
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;

                continue;
            }

            foreach ($this->shelves($row) as $shelf) {
                $counts[$shelf->value]++;
            }

            foreach ($this->ownCollections($row) as $name) {
                $own->count($name);
            }
        }

        $library = new LibraryList(self::SERVICE);
        $categories = array_map(
            fn (LibraryThingShelf $shelf): ManifestCategory => ($label = $shelf->importLabel()) === null
                ? $library->category($counts[$shelf->value])
                : new ManifestCategory($shelf->value, __($label), $counts[$shelf->value]),
            LibraryThingShelf::cases(),
        );

        return ImportManifest::selectable($categories, $own->offered(), $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $owned = (new LibraryList(self::SERVICE))->ref();
        $lists = OwnLists::picked($selection);

        foreach ($selection->has(LibraryThingShelf::Owned->value) ? [$owned, ...$lists] : $lists as $list) {
            yield new ListRow($list);
        }

        foreach ($this->csv($input)->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            if (trim($row['title']) === '') {
                yield new SkipRow('import.skip_librarything_no_title', ['id' => trim($row['book id'])]);

                continue;
            }

            foreach ($this->shelves($row) as $shelf) {
                if ($selection->has($shelf->value)) {
                    yield $this->shelved($row, $shelf, $owned);
                }
            }

            foreach ($this->ownCollections($row) as $name) {
                if (isset($lists[OwnLists::key($name)])) {
                    yield new MediaRow($this->subject($row), lists: [new ImportListEntry($lists[OwnLists::key($name)], $this->date($row, 'entry date'))]);
                }
            }
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['book id', 'title', 'primary author', 'collections'], lowercase: true);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function shelved(array $row, LibraryThingShelf $shelf, ImportListRef $owned): MediaRow
    {
        $subject = $this->subject($row);
        $added = $this->date($row, 'entry date');

        return match ($shelf) {
            LibraryThingShelf::Read => new MediaRow($subject, plays: [new ImportPlay($this->date($row, 'date read'))]),
            LibraryThingShelf::Reading => new MediaRow($subject, status: new ImportStatus(MediaUserStatus::InProgress, $this->date($row, 'date started') ?? $added)),
            LibraryThingShelf::Wanted => new MediaRow($subject, watchlist: new ImportMark($added)),
            LibraryThingShelf::Favorites => new MediaRow($subject, favorite: new ImportMark),
            LibraryThingShelf::Rated => new MediaRow($subject, rating: $this->rated($row)),
            LibraryThingShelf::Owned => new MediaRow($subject, lists: [new ImportListEntry($owned, $added)]),
        };
    }

    /**
     * Every category the book falls in.
     *
     * @param  array<string, string>  $row
     * @return list<LibraryThingShelf>
     */
    private function shelves(array $row): array
    {
        $shelves = [];

        foreach ($this->collections($row) as $name) {
            $shelf = self::COLLECTIONS[mb_strtolower($name)] ?? null;

            if ($shelf !== null) {
                $shelves[$shelf->value] = $shelf;
            }
        }

        // A reading date says the book was read, whatever collection it sits in.
        if ($this->date($row, 'date read') !== null) {
            $shelves[LibraryThingShelf::Read->value] = LibraryThingShelf::Read;
        }

        if ($this->rating($row) !== null) {
            $shelves[LibraryThingShelf::Rated->value] = LibraryThingShelf::Rated;
        }

        return array_values($shelves);
    }

    /**
     * The reader's own collections: those LibraryThing did not make.
     *
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function ownCollections(array $row): array
    {
        return array_values(array_filter($this->collections($row), fn (string $name): bool => ! isset(self::COLLECTIONS[mb_strtolower($name)])));
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function collections(array $row): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $row['collections'])), fn (string $name): bool => $name !== ''));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(array $row): MediaSubject
    {
        $isbn = $this->isbn($row);
        $title = trim($row['title']);

        return MediaSubject::external(
            MediaKind::Book,
            $isbn === null ? [] : [ExternalIdSource::Isbn->value => $isbn],
            $title,
            byTitle: new TitleQuery($title, creator: $this->author($row)),
        );
    }

    /**
     * "ISBN" holds the book's own in brackets; "ISBNs" every edition's, the
     * first one standing for this one.
     *
     * @param  array<string, string>  $row
     */
    private function isbn(array $row): ?string
    {
        return Isbn::thirteen(Payload::text($row, 'isbn')) ?? Isbn::thirteen(explode(',', Payload::text($row, 'isbns'))[0]);
    }

    /**
     * "Cortázar, Julio" as a name is written: first name first.
     *
     * @param  array<string, string>  $row
     */
    private function author(array $row): ?string
    {
        $parts = array_map('trim', explode(',', $row['primary author'], 2));
        $name = trim(count($parts) === 2 ? "{$parts[1]} {$parts[0]}" : $parts[0]);

        return $name === '' ? null : $name;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function rated(array $row): ?ImportRating
    {
        $rating = $this->rating($row);

        return $rating === null ? null : new ImportRating($rating, $this->date($row, 'date read'));
    }

    /**
     * The half stars LibraryThing rates in, 0.5–5.
     *
     * @param  array<string, string>  $row
     */
    private function rating(array $row): ?int
    {
        return RatingScale::readStars(Payload::text($row, 'rating'));
    }

    /**
     * A full day as LibraryThing writes it, "[2007-05-08]"; a year or a
     * month alone names no day.
     *
     * @param  array<string, string>  $row
     */
    private function date(array $row, string $column): ?CarbonImmutable
    {
        return Payload::calendarDay(trim(Payload::text($row, $column), '[]'));
    }
}
