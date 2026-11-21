<?php

namespace App\Integrations\BookWyrm;

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
use App\Integrations\Import\OwnLists;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * A BookWyrm book export (Settings → Export → CSV), from any instance.
 *
 * The export keeps one shelf a book and its latest read-through. The reading
 * shelf decides what a book is: read → a play on the day it was finished,
 * reading → in progress, to-read → the watchlist, stopped-reading → a dropped
 * attempt. A shelf of the reader's own making comes over as a list, opt-in;
 * a book there stands where its read-through says: finished, stopped or
 * begun.
 *
 * Which id columns there are depends on the instance's version, so only the
 * title, the authors and the shelf are required. Books are placed by their
 * Goodreads id and ISBN (an ISBN-10 converted), then by title and the first
 * author.
 */
final class BookWyrmCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'bookwyrm';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.bookwyrm_export_csv');
    }

    public function description(): string
    {
        return __('import.books_you_read_are_reading_want_to');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_bookwyrm');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.on_bookwyrm_open_settings_export'))];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_bookwyrm'));
        }

        $counts = array_fill_keys(array_column(BookWyrmShelf::cases(), 'value'), 0);
        $shelves = new OwnLists;
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;

                continue;
            }

            $shelf = $this->shelf($row);

            $own = $this->ownShelf($row);

            // A book on no shelf at all is a row the import passes over with
            // a word, so the report adds up to what the file holds; one on a
            // shelf of the reader's own alone is that list's.
            if ($shelf !== null) {
                $counts[$shelf->value]++;
            } elseif ($own === null) {
                $unreadable++;
            }

            if ($own !== null) {
                $shelves->count($own);
            }
        }

        $categories = [];

        foreach (BookWyrmShelf::cases() as $shelf) {
            $categories[] = new ManifestCategory($shelf->value, __($shelf->importLabel()), $counts[$shelf->value]);
        }

        return ImportManifest::selectable($categories, $shelves->offered(), $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $csv = $this->csv($input);

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $shelf = $this->shelf($row);

            if ($shelf === null) {
                if ($this->ownShelf($row) === null) {
                    yield new SkipRow('import.skip_bookwyrm_no_shelf', ['label' => $this->bookLabel($row)]);
                }

                continue;
            }

            if ($selection->has($shelf->value)) {
                yield $this->book($row, $shelf);
            }
        }

        if ($selection->listIds === []) {
            return;
        }

        $lists = OwnLists::picked($selection);

        foreach ($lists as $list) {
            yield new ListRow($list);
        }

        foreach ($csv->rows() as $row) {
            $own = $this->ownShelf($row);

            if ($own !== null && isset($lists[OwnLists::key($own)])) {
                yield new MediaRow($this->subject($row), lists: [
                    new ImportListEntry($lists[OwnLists::key($own)], $this->date($row, 'shelf_date')),
                ]);
            }
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['title', 'author_text', 'shelf'], lowercase: true);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function book(array $row, BookWyrmShelf $shelf): MediaRow
    {
        $shelved = $this->date($row, 'shelf_date');
        $finished = $this->date($row, 'finish_date') ?? $shelved;
        $rating = RatingScale::readStars(Payload::text($row, 'rating'));

        return new MediaRow(
            $this->subject($row),
            plays: $shelf === BookWyrmShelf::Read ? [new ImportPlay($finished)] : [],
            rating: $rating === null ? null : new ImportRating($rating, $finished),
            status: $shelf === BookWyrmShelf::Reading ? new ImportStatus(MediaUserStatus::InProgress, $this->date($row, 'start_date') ?? $shelved) : null,
            drop: $shelf === BookWyrmShelf::StoppedReading ? new ImportDrop($this->date($row, 'stopped_date') ?? $shelved) : null,
            watchlist: $shelf === BookWyrmShelf::ToRead ? new ImportMark($shelved) : null,
        );
    }

    /**
     * The reading shelf the book stands for: the one it sits on, or, for a
     * book on a shelf of the reader's own, what its read-through says.
     *
     * @param  array<string, string>  $row
     */
    private function shelf(array $row): ?BookWyrmShelf
    {
        return Payload::enum($row, 'shelf', BookWyrmShelf::class) ?? match (true) {
            $this->date($row, 'finish_date') !== null => BookWyrmShelf::Read,
            $this->date($row, 'stopped_date') !== null => BookWyrmShelf::StoppedReading,
            $this->date($row, 'start_date') !== null => BookWyrmShelf::Reading,
            default => null,
        };
    }

    /**
     * The name of the reader's own shelf the book sits on, if it is one.
     *
     * @param  array<string, string>  $row
     */
    private function ownShelf(array $row): ?string
    {
        $shelf = Payload::text($row, 'shelf');

        if ($shelf === '' || Payload::enum($row, 'shelf', BookWyrmShelf::class) !== null) {
            return null;
        }

        return Payload::string($row, 'shelf_name') ?? $shelf;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(array $row): MediaSubject
    {
        $goodreads = Payload::text($row, 'goodreads_key');
        $isbn = Isbn::thirteen(Payload::text($row, 'isbn_13')) ?? Isbn::thirteen(Payload::text($row, 'isbn_10'));
        $ids = array_filter([
            ExternalIdSource::Goodreads->value => ctype_digit($goodreads) ? $goodreads : null,
            ExternalIdSource::Isbn->value => $isbn,
        ]);

        return MediaSubject::external(
            MediaKind::Book,
            $ids,
            $this->bookLabel($row),
            byTitle: new TitleQuery(trim($row['title']), creator: $this->firstAuthor($row)),
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function bookLabel(array $row): string
    {
        $title = trim($row['title']);

        return $title === '' ? __('import.unnamed_book', ['id' => Payload::text($row, 'remote_id')]) : $title;
    }

    /**
     * "author_text" names every author, comma-separated; the first one is who
     * a search agrees on.
     *
     * @param  array<string, string>  $row
     */
    private function firstAuthor(array $row): ?string
    {
        $first = trim(explode(',', $row['author_text'])[0]);

        return $first === '' ? null : $first;
    }

    /**
     * BookWyrm writes days as "2024-03-01".
     *
     * @param  array<string, string>  $row
     */
    private function date(array $row, string $column): ?CarbonImmutable
    {
        return Payload::instant($row[$column] ?? null);
    }
}
