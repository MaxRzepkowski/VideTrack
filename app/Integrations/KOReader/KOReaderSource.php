<?php

namespace App\Integrations\KOReader;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\KOReader\Data\KOReaderBook;
use Generator;
use LogicException;

/**
 * KOReader's reading statistics ({@see KOReaderStatistics}), the file the
 * e-reader app keeps in its settings folder.
 *
 * A book read to its end is read, on the day its end was reached; one
 * begun is in progress since its first page. The statistics name a book by
 * its title and authors alone (no ISBN), so books are placed by title and
 * the first author. A book only opened, with no page read, says nothing.
 */
final class KOReaderSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'koreader';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.koreader_statistics');
    }

    public function description(): string
    {
        return __('import.books_you_read_to_the_end_or');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_koreader');
    }

    public function accept(): string
    {
        return '.sqlite3';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.koreader_keeps_statistics_sqlite3_in'))];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $statistics = KOReaderStatistics::open((string) $input->path);

        if ($statistics === null) {
            return ImportManifest::invalid(__('import.the_file_is_not_koreader_statistics'));
        }

        $counts = array_fill_keys(array_column(KOReaderState::cases(), 'value'), 0);
        $unread = 0;

        foreach ($statistics->books() as $book) {
            $state = $this->state($book);

            if ($state === null || $book->title === '') {
                $unread++;
            } else {
                $counts[$state->value]++;
            }
        }

        return ImportManifest::selectable(array_map(
            fn (KOReaderState $state): ManifestCategory => new ManifestCategory($state->value, __($state->importLabel()), $counts[$state->value]),
            KOReaderState::cases(),
        ), [], $unread);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $statistics = KOReaderStatistics::open((string) $input->path) ?? throw new LogicException('Rows of KOReader statistics the analysis refused.');

        foreach ($statistics->books() as $book) {
            $state = $this->state($book);

            if ($book->title === '') {
                yield new SkipRow('import.skip_koreader_no_title');

                continue;
            }

            if ($state === null) {
                yield new SkipRow('import.skip_koreader_never_read', ['label' => $book->title]);

                continue;
            }

            if (! $selection->has($state->value)) {
                continue;
            }

            yield new MediaRow(
                MediaSubject::external(MediaKind::Book, [], $book->title, byTitle: new TitleQuery($book->title, creator: $book->author)),
                plays: $state === KOReaderState::Read ? [new ImportPlay($book->finished)] : [],
                status: $state === KOReaderState::Reading ? new ImportStatus(MediaUserStatus::InProgress, $book->started) : null,
            );
        }
    }

    /** Which category the book falls in; null for one never read. */
    private function state(KOReaderBook $book): ?KOReaderState
    {
        return match (true) {
            $book->finished !== null => KOReaderState::Read,
            $book->started !== null => KOReaderState::Reading,
            default => null,
        };
    }
}
