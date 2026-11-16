<?php

namespace App\Integrations\Goodreads;

use App\Accounts\UserCalendar;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaKind;
use App\Catalog\ProgressUnit;
use App\Enums\PlayOutcome;
use App\Integrations\Export\CsvExport;
use App\Integrations\Export\ExportShelf;
use App\Integrations\Export\ExportStandings;
use App\Integrations\Export\ListStatus;
use App\Library\RatingScale;
use App\Models\ListItem;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The user's books as a Goodreads library export, the file StoryGraph and
 * Hardcover import too: the full header Goodreads writes, so an importer that
 * checks it takes the file. It reads back through {@see GoodreadsCsvSource}
 * into the same states, so the shelf rules are that import's, turned round.
 *
 * ISBNs are written as bare digits, not Goodreads' ="…": the formula guard of
 * every export would break that spelling, and importers read the digits.
 */
final class GoodreadsExport extends CsvExport
{
    public const CODE = 'goodreads';

    /** What "My Rating" says of a book with none. */
    private const UNRATED = 0;

    /** "Owned Copies": the library keeps no copies. */
    private const NO_COPIES = 0;

    private const HEADER = [
        'Book Id', 'Title', 'Author', 'Author l-f', 'Additional Authors', 'ISBN', 'ISBN13', 'My Rating',
        'Average Rating', 'Publisher', 'Binding', 'Number of Pages', 'Year Published', 'Original Publication Year',
        'Date Read', 'Date Added', 'Bookshelves', 'Bookshelves with positions', 'Exclusive Shelf', 'My Review',
        'Spoiler', 'Private Notes', 'Read Count', 'Owned Copies',
    ];

    public function __construct(private readonly ExportStandings $standings) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        // A product name, never translated.
        return 'Goodreads';
    }

    public function shelf(): ExportShelf
    {
        return ExportShelf::Service;
    }

    protected function header(): array
    {
        return self::HEADER;
    }

    protected function rows(User $user): iterable
    {
        $calendar = UserCalendar::of($user);

        foreach ($this->standings->of($user, MediaKind::Book) as $title) {
            $book = $title->item;
            $metadata = ItemMetadata::fromStored($book->metadata);
            $authors = $metadata->authors;
            $rated = $title->rating;
            $reads = $title->plays->where('outcome', PlayOutcome::Completed);
            // The first thing the library learned of the book.
            $addedOn = $this->earliest([
                $title->startedAt(),
                $rated?->created_at,
                ...$title->memberships->map(fn (ListItem $item): ?DateTimeInterface => $item->created_at)->all(),
            ]);
            $readOn = $reads->last()?->watched_at;

            yield [
                // The work's ids, the same for every reader: which edition a
                // reader once imported is known to no row of theirs.
                $metadata->goodreadsId(),
                $metadata->originalTitle() ?? $book->title,
                $authors[0] ?? null,
                isset($authors[0]) ? $this->lastFirst($authors[0]) : null,
                implode(', ', array_slice($authors, 1)),
                null,
                $metadata->isbn(),
                $rated === null ? self::UNRATED : RatingScale::toStars($rated->rating),
                null,
                null,
                null,
                $metadata->progressTotal(ProgressUnit::Pages),
                null,
                $book->year,
                $this->goodreadsDay($calendar->day($readOn)),
                $this->goodreadsDay($calendar->day($addedOn)),
                implode(', ', $this->ownShelves($title->memberships)),
                null,
                $this->exclusiveShelf($title->listStatus())->value,
                null,
                null,
                null,
                $reads->count(),
                self::NO_COPIES,
            ];
        }
    }

    /**
     * The shelf of the state a book stands in: Goodreads has no pause, so
     * a paused reading is still currently-reading, and a book only on the
     * user's own lists waits on to-read, Goodreads' shelf for any book added.
     */
    private function exclusiveShelf(?ListStatus $status): GoodreadsShelf
    {
        return match ($status) {
            ListStatus::Current, ListStatus::OnHold => GoodreadsShelf::CurrentlyReading,
            ListStatus::Completed => GoodreadsShelf::Read,
            ListStatus::Dropped => GoodreadsShelf::DidNotFinish,
            ListStatus::Planned, null => GoodreadsShelf::ToRead,
        };
    }

    /**
     * The user's own lists the book is on, named as Goodreads names a
     * shelf: lowercase, hyphens for spaces, no commas (the column is a
     * comma list).
     *
     * @param  Collection<int, ListItem>  $memberships
     * @return list<string>
     */
    private function ownShelves(Collection $memberships): array
    {
        $shelves = [];

        foreach ($memberships as $item) {
            $list = $item->list;
            $shelf = $list === null || $list->isSystem() ? '' : Str::slug($list->displayTitle());

            if ($shelf !== '') {
                $shelves[$shelf] = $shelf;
            }
        }

        return array_values($shelves);
    }

    /** "Frank Herbert" as Goodreads files him: "Herbert, Frank". */
    private function lastFirst(string $name): string
    {
        $name = trim($name);
        $space = mb_strrpos($name, ' ');

        return $space === false ? $name : mb_substr($name, $space + 1).', '.mb_substr($name, 0, $space);
    }

    /** @param  list<DateTimeInterface|null>  $moments */
    private function earliest(array $moments): ?DateTimeInterface
    {
        $known = array_filter($moments);

        return $known === [] ? null : min($known);
    }

    /** A calendar day (Y-m-d) as Goodreads spells it: 2026/03/01. */
    private function goodreadsDay(?string $day): ?string
    {
        return $day === null ? null : str_replace('-', '/', $day);
    }
}
