<?php

namespace App\Integrations\Goodreads;

use App\Accounts\UserCalendar;
use App\Catalog\ItemMetadata;
use App\Catalog\ProgressUnit;
use App\Enums\PlayOutcome;
use App\Enums\PositionStatus;
use App\Integrations\Export\CsvExport;
use App\Integrations\Export\ExportShelf;
use App\Library\RatingScale;
use App\Models\LibraryEntry;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
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

    private const BOOK = 'book';

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
        $books = $this->books($user);
        $ids = $books->modelKeys();

        $statuses = LibraryEntry::query()->ofRoots()->where('user_id', $user->id)->whereIn('media_item_id', $ids)
            ->get(['media_item_id', 'status'])->keyBy('media_item_id');
        $ratings = Rating::query()->where('user_id', $user->id)->whereIn('media_item_id', $ids)->get()->keyBy('media_item_id');
        $plays = Play::query()->where('user_id', $user->id)->whereIn('media_item_id', $ids)->get()->groupBy('media_item_id');
        $opened = MediaUserState::query()->where('user_id', $user->id)->whereIn('media_item_id', $ids)->get()->keyBy('media_item_id');
        $lists = MediaList::query()->where('user_id', $user->id)->get(['id', 'system_key', 'title'])->keyBy('id');
        $listed = ListItem::query()->whereIn('list_id', $lists->modelKeys())->whereIn('media_item_id', $ids)->get()->groupBy('media_item_id');
        $calendar = UserCalendar::of($user);

        foreach ($books as $book) {
            $metadata = ItemMetadata::fromStored($book->metadata);
            $authors = $metadata->authors;
            $rated = $ratings->get($book->id);
            $history = $plays->get($book->id);
            $reads = $history?->where('outcome', PlayOutcome::Completed);
            $memberships = $listed->get($book->id);
            $onBacklog = $memberships?->contains(fn (ListItem $item): bool => $lists->get($item->list_id)?->system_key === MediaList::SYSTEM_WATCHLIST) ?? false;
            // The first thing the library learned of the book.
            $addedOn = $this->earliest([
                ...$history?->map(fn (Play $play): DateTimeInterface => $play->watched_at)->all() ?? [],
                $rated?->created_at,
                $opened->get($book->id)?->created_at,
                ...$memberships?->map(fn (ListItem $item): ?DateTimeInterface => $item->created_at)->all() ?? [],
            ]);
            $readOn = $reads?->sortByDesc('watched_at')->first()?->watched_at;

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
                implode(', ', $memberships === null ? [] : $this->ownShelves($memberships, $lists)),
                null,
                $this->exclusiveShelf($statuses->get($book->id)?->status, $reads?->isNotEmpty() ?? false, $onBacklog, $rated !== null)->value,
                null,
                null,
                null,
                $reads?->count() ?? 0,
                self::NO_COPIES,
            ];
        }
    }

    /**
     * Every book the user has a word about: history, a rating, an open
     * attempt or a place on one of their lists.
     *
     * @return Collection<int, MediaItem>
     */
    private function books(User $user): Collection
    {
        return MediaItem::query()
            ->where('type', self::BOOK)
            ->where(fn (Builder $query) => $query
                ->whereIn('id', LibraryEntry::query()->select('media_item_id')->where('user_id', $user->id))
                ->orWhereIn('id', Rating::query()->select('media_item_id')->where('user_id', $user->id))
                ->orWhereIn('id', MediaUserState::query()->select('media_item_id')->where('user_id', $user->id))
                ->orWhereIn('id', ListItem::query()->select('media_item_id')
                    ->whereIn('list_id', MediaList::query()->select('id')->where('user_id', $user->id))))
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }

    /**
     * What a book stands on, one shelf, the open attempt first: a re-read
     * in progress is currently-reading, as Goodreads shows it. A finished
     * read outweighs a later drop, and a rating alone is a read book, which
     * is where Goodreads puts a book rated there. A book only on the user's
     * own lists waits on to-read, Goodreads' shelf for any book added.
     */
    private function exclusiveShelf(?PositionStatus $status, bool $read, bool $onBacklog, bool $rated): GoodreadsShelf
    {
        return match (true) {
            $status === PositionStatus::InProgress, $status === PositionStatus::OnHold => GoodreadsShelf::CurrentlyReading,
            $read => GoodreadsShelf::Read,
            $status === PositionStatus::Dropped => GoodreadsShelf::DidNotFinish,
            $onBacklog => GoodreadsShelf::ToRead,
            $rated => GoodreadsShelf::Read,
            default => GoodreadsShelf::ToRead,
        };
    }

    /**
     * The user's own lists the book is on, named as Goodreads names a
     * shelf: lowercase, hyphens for spaces, no commas (the column is a
     * comma list).
     *
     * @param  Collection<int, ListItem>  $memberships
     * @param  Collection<int, MediaList>  $lists
     * @return list<string>
     */
    private function ownShelves(Collection $memberships, Collection $lists): array
    {
        $shelves = [];

        foreach ($memberships as $item) {
            $list = $lists->get($item->list_id);
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
