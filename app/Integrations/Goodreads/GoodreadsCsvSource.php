<?php

namespace App\Integrations\Goodreads;

use App\Catalog\ExternalIdSource;
use App\Catalog\Isbn;
use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportDrop;
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
use App\Integrations\Import\Data\ManifestList;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * A Goodreads library export (My Books → Import and export). Goodreads' API is
 * gone, so the CSV is the only way out.
 *
 * The exclusive shelf decides what a book is: read → a play on the day it
 * was read, currently-reading → in progress, to-read → the watchlist,
 * did-not-finish (a shelf many readers add) → a dropped attempt. Any other
 * exclusive shelf has no counterpart and is reported. The remaining shelves
 * are the user's own and come over as custom lists, opt-in.
 *
 * Books are placed by their Goodreads id, where our book provider maps one,
 * then by ISBN-13 (an ISBN-10 is converted: our book provider only indexes
 * 13-digit ones), and by title and author when the edition has neither —
 * common for Kindle editions.
 */
final class GoodreadsCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'goodreads';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.goodreads_export_csv');
    }

    public function description(): string
    {
        return __('import.books_you_read_are_reading_and');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_goodreads');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.on_goodreads_open_my_books_import'), 'https://www.goodreads.com/review/import'),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_goodreads'));
        }

        $counts = array_fill_keys(array_column(GoodreadsShelf::cases(), 'value'), 0);
        $shelves = [];
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;

                continue;
            }

            $shelf = $this->exclusiveShelf($row);

            // A shelf with no counterpart here is a row the import passes
            // over with a word: counted with the unreadable ones, so the
            // report adds up to what the file holds.
            if (isset($counts[$shelf])) {
                $counts[$shelf]++;
            } else {
                $unreadable++;
            }

            foreach ($this->ownShelves($row) as $own) {
                $shelves[$own] = ($shelves[$own] ?? 0) + 1;
            }
        }

        $categories = [];

        foreach (GoodreadsShelf::cases() as $shelf) {
            $categories[] = new ManifestCategory($shelf->value, __($shelf->importLabel()), $counts[$shelf->value]);
        }

        $lists = [];

        foreach ($shelves as $name => $count) {
            $lists[] = new ManifestList((string) $name, (string) $name, $count, false);
        }

        return ImportManifest::selectable($categories, $lists, $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $csv = $this->csv($input);

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $shelf = GoodreadsShelf::tryFrom($this->exclusiveShelf($row));

            if ($shelf === null) {
                yield new SkipRow('import.skip_goodreads_shelf', ['label' => $this->bookLabel($row), 'shelf' => $this->exclusiveShelf($row)]);

                continue;
            }

            if ($selection->has($shelf->value)) {
                yield $this->shelved($row, $shelf);
            }
        }

        if ($selection->listIds === []) {
            return;
        }

        $lists = [];

        foreach ($selection->listIds as $name) {
            $lists[$name] = new ImportListRef($name);

            yield new ListRow($lists[$name]);
        }

        // One row per membership, as the manifest counted them.
        foreach ($csv->rows() as $row) {
            foreach ($this->ownShelves($row) as $own) {
                if (isset($lists[$own])) {
                    yield new MediaRow($this->subject($row), lists: [
                        new ImportListEntry($lists[$own], $this->date($row, 'date added')),
                    ]);
                }
            }
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['book id', 'title', 'exclusive shelf'], lowercase: true);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function shelved(array $row, GoodreadsShelf $shelf): MediaRow
    {
        $added = $this->date($row, 'date added');
        $rating = $this->rating($row);

        return new MediaRow(
            $this->subject($row),
            plays: $shelf === GoodreadsShelf::Read
                ? [new ImportPlay($this->date($row, 'date read') ?? $added, amount: $this->pages($row))]
                : [],
            rating: $rating === null ? null : new ImportRating($rating, $this->date($row, 'date read') ?? $added),
            status: $shelf === GoodreadsShelf::CurrentlyReading ? new ImportStatus(MediaUserStatus::InProgress, $added) : null,
            drop: $shelf === GoodreadsShelf::DidNotFinish ? new ImportDrop($added) : null,
            watchlist: $shelf === GoodreadsShelf::ToRead ? new ImportMark($added) : null,
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(array $row): MediaSubject
    {
        // Goodreads' own id first: it names the edition the reader shelved.
        $ids = array_filter([ExternalIdSource::Goodreads->value => trim($row['book id'])]);
        $isbn = $this->isbn13($row);

        if ($isbn !== null) {
            $ids[ExternalIdSource::Isbn->value] = $isbn;
        }

        return MediaSubject::external(
            MediaKind::Book,
            $ids,
            $this->bookLabel($row),
            byTitle: new TitleQuery(self::workTitle($row['title']), creator: trim($row['author'] ?? '') ?: null),
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function bookLabel(array $row): string
    {
        $title = trim($row['title']);

        return $title === '' ? __('import.unnamed_book', ['id' => trim($row['book id'])]) : $title;
    }

    /**
     * "The Wise Man's Fear (The Kingkiller Chronicle, #2)" searches as
     * "The Wise Man's Fear" — the series note is Goodreads', not the title's.
     * Read without a regex: a title is a field of an uploaded file, and the
     * obvious pattern backtracks quadratically on a long run of "#".
     */
    public static function workTitle(string $title): string
    {
        $title = trim($title);
        $open = strrpos($title, '(');

        if (! str_ends_with($title, ')') || $open === false) {
            return $title;
        }

        $note = substr($title, $open + 1, -1);

        return str_contains($note, '#') && ! str_contains($note, ')') ? rtrim(substr($title, 0, $open)) : $title;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function exclusiveShelf(array $row): string
    {
        return mb_strtolower(trim($row['exclusive shelf']));
    }

    /**
     * The user's own shelves: everything in Bookshelves except the exclusive
     * ones, which already decided the book's state.
     *
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function ownShelves(array $row): array
    {
        $shelves = array_map('trim', explode(',', $row['bookshelves'] ?? ''));

        return array_values(array_filter(
            $shelves,
            fn (string $shelf): bool => $shelf !== ''
                && GoodreadsShelf::tryFrom(mb_strtolower($shelf)) === null
                && mb_strtolower($shelf) !== $this->exclusiveShelf($row),
        ));
    }

    /**
     * "My Rating" is 0–5 stars (0 = unrated), "5" in old exports and "5.0" in
     * new ones.
     *
     * @param  array<string, string>  $row
     */
    private function rating(array $row): ?int
    {
        return RatingScale::readStars(Payload::text($row, 'my rating'));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function pages(array $row): ?int
    {
        $pages = trim($row['number of pages'] ?? '');

        return ctype_digit($pages) && (int) $pages > 0 ? (int) $pages : null;
    }

    /**
     * The ISBN-13 of the edition. Goodreads wraps both columns as ="…" so a
     * spreadsheet keeps the leading zeros; an edition with only an ISBN-10
     * gets its 978 form.
     *
     * @param  array<string, string>  $row
     */
    private function isbn13(array $row): ?string
    {
        return Isbn::thirteen($row['isbn13'] ?? '') ?? Isbn::thirteen($row['isbn'] ?? '');
    }

    /**
     * Goodreads dates read "2021/10/11".
     *
     * @param  array<string, string>  $row
     */
    private function date(array $row, string $column): ?CarbonImmutable
    {
        return Payload::instant($row[$column] ?? null);
    }
}
