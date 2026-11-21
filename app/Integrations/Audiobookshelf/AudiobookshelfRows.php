<?php

namespace App\Integrations\Audiobookshelf;

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
use App\Support\Payload;

/**
 * One media progress of an Audiobookshelf user (from `GET /api/me`) with the
 * library item it points at, as an import row.
 *
 * Only books: a podcast episode's progress names an episode, and podcasts
 * have nowhere to go here. A book Audiobookshelf calls finished is read once,
 * on the day it was finished; one listened to or read in part is in
 * progress since it was begun, with no counter (a position in hours means
 * nothing on a page count). One only opened says nothing.
 *
 * A book is placed by its ISBN, else by its title and first author.
 */
final class AudiobookshelfRows
{
    /**
     * Whether a progress is a book's, finished or begun: the only ones worth
     * asking Audiobookshelf about the item.
     *
     * @param  array<array-key, mixed>  $progress
     */
    public function wanted(array $progress): bool
    {
        if (Payload::enum($progress, 'mediaItemType', AudiobookshelfMediaType::class) !== AudiobookshelfMediaType::Book) {
            return false;
        }

        return Payload::bool($progress, 'isFinished') === true
            || (Payload::float($progress, 'progress') ?? 0) > 0
            || (Payload::float($progress, 'ebookProgress') ?? 0) > 0;
    }

    /**
     * @param  array<array-key, mixed>  $progress
     * @param  array<array-key, mixed>  $item
     */
    public function row(array $progress, array $item): MediaRow|SkipRow
    {
        $book = Payload::map(data_get($item, 'media.metadata'));
        $title = Payload::string($book, 'title');

        if ($title === null) {
            return new SkipRow('import.skip_audiobookshelf_no_title');
        }

        $isbn = Isbn::thirteen(Payload::text($book, 'isbn'));
        $subject = MediaSubject::external(
            MediaKind::Book,
            $isbn === null ? [] : [ExternalIdSource::Isbn->value => $isbn],
            $title,
            byTitle: new TitleQuery($title, creator: Payload::names($book['authors'] ?? null)[0] ?? null),
        );

        return Payload::bool($progress, 'isFinished') === true
            ? new MediaRow($subject, plays: [new ImportPlay(Payload::epochMilliseconds($progress, 'finishedAt'))])
            : new MediaRow($subject, status: new ImportStatus(MediaUserStatus::InProgress, Payload::epochMilliseconds($progress, 'startedAt')));
    }
}
