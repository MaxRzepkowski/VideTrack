<?php

namespace App\Integrations\StoryGraph;

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
 * A StoryGraph library export (Manage Account → Export StoryGraph Library).
 *
 * The read status decides what a book is: read → a play on the day it was
 * last read, currently-reading → in progress, paused → on hold, to-read →
 * the watchlist, did-not-finish → a dropped attempt. A book with no status
 * stands only on the reader's own tags, which come over as lists, opt-in.
 *
 * "Read Count" says how often a book was read, never when the earlier reads
 * were, so a book read twice is one play: an undated read would land on the
 * book's release day. Books are placed by ISBN (an ISBN-10 converted; the
 * column holds an ASIN for some ebooks, which leads nowhere), then by title
 * and the first author.
 */
final class StoryGraphCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'storygraph';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.storygraph_export_csv');
    }

    public function description(): string
    {
        return __('import.books_you_read_are_reading_paused');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_storygraph');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.on_storygraph_open_manage_account_export'), 'https://app.thestorygraph.com/manage-account'),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_storygraph'));
        }

        $counts = array_fill_keys(array_column(StoryGraphStatus::cases(), 'value'), 0);
        $tags = new OwnLists;
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;

                continue;
            }

            $status = $this->status($row);

            // A book without a status is a row the import passes over with
            // a word, so the report adds up to what the file holds.
            if ($status === null) {
                $unreadable++;
            } else {
                $counts[$status->value]++;
            }

            foreach ($this->tags($row) as $tag) {
                $tags->count($tag);
            }
        }

        $categories = [];

        foreach (StoryGraphStatus::cases() as $status) {
            $categories[] = new ManifestCategory($status->value, __($status->importLabel()), $counts[$status->value]);
        }

        return ImportManifest::selectable($categories, $tags->offered(), $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $csv = $this->csv($input);

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $status = $this->status($row);

            if ($status === null) {
                yield new SkipRow('import.skip_storygraph_no_status', ['label' => $this->bookLabel($row)]);

                continue;
            }

            if ($selection->has($status->value)) {
                yield $this->book($row, $status);
            }
        }

        if ($selection->listIds === []) {
            return;
        }

        $lists = OwnLists::picked($selection);

        foreach ($lists as $list) {
            yield new ListRow($list);
        }

        // One row per membership, as the manifest counted them.
        foreach ($csv->rows() as $row) {
            foreach ($this->tags($row) as $tag) {
                if (isset($lists[OwnLists::key($tag)])) {
                    yield new MediaRow($this->subject($row), lists: [
                        new ImportListEntry($lists[OwnLists::key($tag)], $this->date($row, 'date added')),
                    ]);
                }
            }
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['title', 'authors', 'isbn/uid', 'read status'], lowercase: true);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function book(array $row, StoryGraphStatus $status): MediaRow
    {
        $added = $this->date($row, 'date added');
        $read = $this->date($row, 'last date read') ?? $added;
        $rating = $this->rating($row);

        return new MediaRow(
            $this->subject($row),
            plays: $status === StoryGraphStatus::Read ? [new ImportPlay($read)] : [],
            rating: $rating === null ? null : new ImportRating($rating, $read),
            status: match ($status) {
                StoryGraphStatus::CurrentlyReading => new ImportStatus(MediaUserStatus::InProgress, $added),
                StoryGraphStatus::Paused => new ImportStatus(MediaUserStatus::OnHold, $added),
                default => null,
            },
            drop: $status === StoryGraphStatus::DidNotFinish ? new ImportDrop($read) : null,
            watchlist: $status === StoryGraphStatus::ToRead ? new ImportMark($added) : null,
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(array $row): MediaSubject
    {
        $isbn = Isbn::thirteen($row['isbn/uid']);

        return MediaSubject::external(
            MediaKind::Book,
            $isbn === null ? [] : [ExternalIdSource::Isbn->value => $isbn],
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

        return $title === '' ? __('import.unnamed_book', ['id' => trim($row['isbn/uid'])]) : $title;
    }

    /**
     * "Authors" lists every author, comma-separated; the first one is who a
     * search agrees on.
     *
     * @param  array<string, string>  $row
     */
    private function firstAuthor(array $row): ?string
    {
        $first = trim(explode(',', $row['authors'])[0]);

        return $first === '' ? null : $first;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function status(array $row): ?StoryGraphStatus
    {
        return StoryGraphStatus::tryFrom(mb_strtolower(trim($row['read status'])));
    }

    /**
     * The reader's own tags, comma-separated.
     *
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function tags(array $row): array
    {
        return array_values(array_filter(array_map('trim', explode(',', Payload::text($row, 'tags'))), fn (string $tag): bool => $tag !== ''));
    }

    /**
     * "Star Rating" in quarter stars, 0.25–5; blank is unrated.
     *
     * @param  array<string, string>  $row
     */
    private function rating(array $row): ?int
    {
        return RatingScale::readStars(Payload::text($row, 'star rating'));
    }

    /**
     * StoryGraph dates read "2023/11/10".
     *
     * @param  array<string, string>  $row
     */
    private function date(array $row, string $column): ?CarbonImmutable
    {
        return Payload::instant($row[$column] ?? null);
    }
}
