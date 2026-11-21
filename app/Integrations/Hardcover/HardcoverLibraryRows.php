<?php

namespace App\Integrations\Hardcover;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * One book of a Hardcover library as an import row. A Hardcover book id is
 * our book's ref, so nothing is matched by title.
 *
 * Every finished read-through is a read on the day it finished, whatever
 * the book's status now. The status says the rest: want to read → the
 * watchlist, currently reading → in progress, paused → on hold, did not
 * finish → dropped. A book marked read with no finished read-through is
 * one read on its last read date, or undated. Ignored books stay out.
 */
final class HardcoverLibraryRows
{
    /**
     * @param  array<array-key, mixed>  $book  one `user_books` entry
     */
    public function book(array $book): MediaRow|SkipRow|null
    {
        $id = Payload::id($book['book_id'] ?? null);
        $status = Payload::intEnum($book, 'status_id', HardcoverReadingStatus::class);

        if ($id === null || $status === HardcoverReadingStatus::Ignored) {
            return null;
        }

        $label = Payload::string(Payload::map($book['book'] ?? null), 'title') ?? __('import.unnamed_book', ['id' => $id]);

        if ($status === null) {
            return new SkipRow('import.skip_hardcover_unknown_status', ['label' => $label]);
        }

        $reads = Payload::list($book['user_book_reads'] ?? null);
        $latest = $reads === [] ? [] : $reads[count($reads) - 1];
        $finished = array_values(array_filter(array_map(fn (array $read): ?CarbonImmutable => Payload::instant($read['finished_at'] ?? null), $reads)));
        $lastRead = Payload::instant($book['last_read_date'] ?? null);
        $started = Payload::instant($latest['started_at'] ?? null) ?? Payload::instant($book['first_started_reading_date'] ?? null);
        $progress = Payload::positiveInt($latest, 'progress_pages');

        $plays = array_map(fn (CarbonImmutable $at): ImportPlay => new ImportPlay($at), $finished);

        if ($plays === [] && $status === HardcoverReadingStatus::Read) {
            $plays = [new ImportPlay($lastRead)];
        }

        $rating = RatingScale::stars(Payload::float($book, 'rating'));

        return new MediaRow(
            MediaSubject::direct(MediaKind::Book->value, 'hardcover', $id, $label),
            plays: $plays,
            rating: $rating === null ? null : new ImportRating($rating, $finished === [] ? $lastRead : max($finished)),
            status: match ($status) {
                HardcoverReadingStatus::CurrentlyReading => new ImportStatus(MediaUserStatus::InProgress, $started, $progress),
                HardcoverReadingStatus::Paused => new ImportStatus(MediaUserStatus::OnHold, $started, $progress),
                default => null,
            },
            drop: $status === HardcoverReadingStatus::DidNotFinish ? new ImportDrop($lastRead ?? $started, $progress) : null,
            watchlist: $status === HardcoverReadingStatus::WantToRead ? new ImportMark(Payload::instant($book['date_added'] ?? null)) : null,
        );
    }
}
