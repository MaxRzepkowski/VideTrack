<?php

namespace App\Integrations\Storyteller;

use App\Catalog\ExternalIdSource;
use App\Catalog\Isbn;
use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlayDating;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * One book of a Storyteller library (`GET /api/v2/books`) with the user's
 * status and position, as an import row.
 *
 * Read is one read; it is dated by the position only when that position
 * stands at the end, where Storyteller itself marks a book read — a book
 * marked read by hand has no day, and none is made up. The position's moment
 * moves on whenever the book is opened again, so the read is dated as the
 * last activity, which keeps a second run from making it two. Reading is in
 * progress since the first position was kept, with no counter (a place in
 * an e-book is no page of an edition). Any other status says nothing.
 *
 * A book is placed by the Hardcover id Storyteller keeps for it, else by
 * its ISBN, else by its title and first author.
 */
final class StorytellerRows
{
    /** Where Storyteller marks a book read on its own (its positions.ts). */
    private const FINISHED_PROGRESSION = 0.98;

    private const HARDCOVER_BOOK = 'hardcover-book-id';

    private const ISBN = 'isbn-13';

    /**
     * @param  array<array-key, mixed>  $book
     */
    public function row(array $book): MediaRow|SkipRow|null
    {
        $status = Payload::enum(Payload::map($book['status'] ?? null), 'name', StorytellerStatus::class);

        if (! in_array($status, [StorytellerStatus::Reading, StorytellerStatus::Read], true)) {
            return null;
        }

        $title = Payload::string($book, 'title');

        if ($title === null) {
            return new SkipRow('import.skip_storyteller_no_title');
        }

        $position = Payload::map($book['position'] ?? null);
        $subject = $this->subject($book, $title);

        return $status === StorytellerStatus::Read
            ? new MediaRow($subject, plays: [new ImportPlay($this->finishedAt($position), dating: PlayDating::Activity)])
            : new MediaRow($subject, status: new ImportStatus(MediaUserStatus::InProgress, Payload::instant($position['createdAt'] ?? null, 'UTC')));
    }

    /**
     * @param  array<array-key, mixed>  $book
     */
    private function subject(array $book, string $title): MediaSubject
    {
        $ids = $this->identifiers($book);

        if (isset($ids[self::HARDCOVER_BOOK])) {
            return MediaSubject::direct(MediaKind::Book->value, 'hardcover', $ids[self::HARDCOVER_BOOK], $title);
        }

        $isbn = isset($ids[self::ISBN]) ? Isbn::thirteen($ids[self::ISBN]) : null;

        return MediaSubject::external(
            MediaKind::Book,
            $isbn === null ? [] : [ExternalIdSource::Isbn->value => $isbn],
            $title,
            byTitle: new TitleQuery($title, creator: Payload::names($book['authors'] ?? null)[0] ?? null),
        );
    }

    /**
     * The book's identifiers by kind, its readaloud's, e-book's and
     * audiobook's after its own: the first of a kind wins.
     *
     * @param  array<array-key, mixed>  $book
     * @return array<string, string>
     */
    private function identifiers(array $book): array
    {
        $ids = [];
        $lists = [$book['identifiers'] ?? null, data_get($book, 'readaloud.identifiers'), data_get($book, 'ebook.identifiers'), data_get($book, 'audiobook.identifiers')];

        foreach ($lists as $list) {
            foreach (Payload::list($list) as $identifier) {
                $kind = Payload::string($identifier, 'kind');
                $value = Payload::string($identifier, 'value');

                if ($kind !== null && $value !== null) {
                    $ids[$kind] ??= $value;
                }
            }
        }

        return $ids;
    }

    /**
     * The moment of the last position, when it stands at the end.
     *
     * @param  array<array-key, mixed>  $position
     */
    private function finishedAt(array $position): ?CarbonImmutable
    {
        $progression = Payload::float(Payload::map(data_get($position, 'locator.locations')), 'totalProgression');

        return $progression !== null && $progression >= self::FINISHED_PROGRESSION ? Payload::epochMilliseconds($position, 'timestamp') : null;
    }
}
