<?php

namespace App\Integrations\Calibre;

use App\Catalog\ExternalIdSource;
use App\Catalog\Isbn;
use App\Catalog\MediaKind;
use App\Integrations\Calibre\Data\CalibreBook;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\ImportCategory;
use App\Integrations\Import\LibraryList;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use Generator;
use LogicException;

/**
 * A Calibre library: the `metadata.db` file in its folder. Calibre holds the
 * books someone owns, not the ones they read, so every book goes on a
 * "Calibre" list, dated the day it was added, and its rating comes along;
 * there is no history to bring. Books are placed by their ISBN or Goodreads
 * id where Calibre has one, then by title and first author.
 */
final class CalibreSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'calibre';

    /** The service the list of the books is named after. */
    private const SERVICE = 'Calibre';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.calibre_library_metadata_db');
    }

    public function description(): string
    {
        return __('import.books_in_your_calibre_library_on');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_calibre');
    }

    public function accept(): string
    {
        return '.db';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.in_calibre_the_library_folder_holds'))];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $library = CalibreLibrary::open((string) $input->path);

        if ($library === null) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_calibre'));
        }

        $rated = 0;

        foreach ($library->books() as $book) {
            $rated += $this->rating($book) === null ? 0 : 1;
        }

        return ImportManifest::selectable([
            (new LibraryList(self::SERVICE))->category($library->count()),
            ImportCategory::Ratings->counted($rated),
        ]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $calibre = CalibreLibrary::open((string) $input->path) ?? throw new LogicException('Rows of a Calibre library the analysis refused.');
        $library = new LibraryList(self::SERVICE);
        $list = $library->ref();
        $listing = $library->isPicked($selection);
        $rating = $selection->has(ImportCategory::Ratings->value);

        if ($listing) {
            yield new ListRow($list);
        }

        foreach ($calibre->books() as $book) {
            if ($book->title === '') {
                yield new SkipRow('import.skip_calibre_no_title', ['id' => $book->id]);

                continue;
            }

            if ($listing) {
                yield new MediaRow($this->subject($book), lists: [new ImportListEntry($list, $book->added)]);
            }

            $stars = $this->rating($book);

            if ($rating && $stars !== null) {
                yield new MediaRow($this->subject($book), rating: new ImportRating($stars));
            }
        }
    }

    private function subject(CalibreBook $book): MediaSubject
    {
        $isbn = $book->isbn === null ? null : Isbn::thirteen($book->isbn);
        $ids = array_filter([
            ExternalIdSource::Isbn->value => $isbn,
            ExternalIdSource::Goodreads->value => $book->goodreads !== null && ctype_digit($book->goodreads) ? $book->goodreads : null,
        ]);

        return MediaSubject::external(
            MediaKind::Book,
            $ids,
            $book->title,
            byTitle: new TitleQuery($book->title, $book->year, $book->author),
        );
    }

    /** Calibre rates on our scale already: two points a star. */
    private function rating(CalibreBook $book): ?int
    {
        return $book->rating !== null && RatingScale::holds($book->rating) ? $book->rating : null;
    }
}
