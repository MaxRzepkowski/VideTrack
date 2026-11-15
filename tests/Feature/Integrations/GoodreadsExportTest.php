<?php

use App\Actions\Library\AddToList;
use App\Actions\Library\CreateList;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\SetMediaStatus;
use App\Catalog\ExternalIdSource;
use App\Catalog\ItemMetadata;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Goodreads\GoodreadsCsvSource;
use App\Integrations\Goodreads\GoodreadsExport;
use App\Integrations\Goodreads\GoodreadsShelf;
use App\Integrations\Import\Data\ImportSelection;
use App\Library\Data\ListTarget;
use App\Library\WatchedAt;
use App\Models\ExternalIdMapping;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  array<string, mixed>  $metadata
 */
function exportedBook(string $title, string $goodreadsId, array $metadata = []): MediaItem
{
    return MediaItem::factory()->book()->create([
        'title' => $title,
        // Hardcover's id stands in as the Goodreads one: the round trip places a book by it.
        'provider_ref' => $goodreadsId,
        'year' => 1965,
        'metadata' => ['pages' => 320, 'authors' => ['Frank Herbert'], 'goodreadsId' => $goodreadsId, ...$metadata],
    ]);
}

/**
 * The download read back as rows keyed by the header.
 *
 * @return list<array<string, string>>
 */
function goodreadsRows(User $user): array
{
    $content = test()->actingAs($user)->get(route('settings.export.download', GoodreadsExport::CODE))->assertOk()->streamedContent();
    $lines = array_map(str_getcsv(...), array_values(array_filter(explode("\n", $content))));
    $header = array_shift($lines);

    return array_map(fn (array $line): array => array_combine($header, $line), $lines);
}

/**
 * @param  list<array<string, string>>  $rows
 * @return array<string, string>
 */
function goodreadsRow(array $rows, string $title): array
{
    return collect($rows)->firstOrFail(fn (array $row): bool => $row['Title'] === $title);
}

/** A reader's books in every state the file has a shelf for. */
function seedReader(User $user): void
{
    $hobbit = exportedBook('Hobbit, czyli tam i z powrotem', '5907', [
        'authors' => ['J.R.R. Tolkien'],
        'isbn' => '9780618260300',
        'originalTitle' => 'The Hobbit',
        'pages' => 300,
    ]);
    $dune = exportedBook('Dune', '44767458', ['authors' => ['Frank Herbert', 'Brian Herbert']]);
    $dropped = exportedBook('Children of Dune', '44767460');
    $wanted = exportedBook('Dune Messiah', '44767459');

    app(MarkWatched::class)->execute($user, $hobbit, WatchedAt::at(CarbonImmutable::parse('2026-03-01 20:00')));
    app(RateItem::class)->execute($user, $hobbit, 10);
    app(SetMediaStatus::class)->execute($user, $dune, MediaUserStatus::InProgress);
    app(DropAttempt::class)->execute($user, $dropped, WatchedAt::at(CarbonImmutable::parse('2026-04-02 20:00')));
    app(AddToList::class)->execute($user, $user->watchlist(), ListTarget::item($wanted->id));

    $list = app(CreateList::class)->execute($user, 'Fantasy na zimę');
    app(AddToList::class)->execute($user, $list, ListTarget::item($hobbit->id));
}

test('the Goodreads export is offered only with the books module on', function () {
    $this->actingAs(User::factory()->create())->get(route('settings.export.download', GoodreadsExport::CODE))->assertNotFound();
});

test('the Goodreads export writes the full header Goodreads writes', function () {
    enableBooksModule();
    $fixture = (string) file_get_contents(base_path('tests/Fixtures/transfer/goodreads/library.csv'));

    $content = $this->actingAs(User::factory()->create())->get(route('settings.export.download', GoodreadsExport::CODE))->streamedContent();

    // Quoted where a name has a space, as any CSV writer does; the names and their order are Goodreads'.
    expect(str_getcsv((string) strtok($content, "\n")))->toBe(str_getcsv((string) strtok($fixture, "\n")));
});

test('each book stands on the shelf of what it is to the reader', function () {
    enableBooksModule();
    $user = User::factory()->create();
    seedReader($user);

    $rows = goodreadsRows($user);

    expect(array_column($rows, 'Exclusive Shelf', 'Title'))->toBe([
        'Children of Dune' => 'did-not-finish',
        'Dune' => 'currently-reading',
        'Dune Messiah' => 'to-read',
        'The Hobbit' => 'read',
    ]);
});

test('a read book carries its ids, authors, stars, dates and the reader\'s own lists', function () {
    enableBooksModule();
    $user = User::factory()->create();
    seedReader($user);

    $hobbit = goodreadsRow(goodreadsRows($user), 'The Hobbit');
    $dune = goodreadsRow(goodreadsRows($user), 'Dune');

    expect($hobbit)->toMatchArray([
        'Book Id' => '5907',
        'Author' => 'J.R.R. Tolkien',
        'Author l-f' => 'Tolkien, J.R.R.',
        'ISBN' => '',
        'ISBN13' => '9780618260300',
        'My Rating' => '5',
        'Number of Pages' => '300',
        'Original Publication Year' => '1965',
        'Date Read' => '2026/03/01',
        'Date Added' => '2026/03/01',
        'Bookshelves' => 'fantasy-na-zime',
        'Read Count' => '1',
    ])->and($dune)->toMatchArray([
        'Additional Authors' => 'Brian Herbert',
        'My Rating' => '0',
        'Date Read' => '',
        // Opened today, on the frozen clock.
        'Date Added' => '2026/10/10',
        'Bookshelves' => '',
        'Read Count' => '0',
    ]);
});

test('an open re-read is currently-reading, and a finished read outweighs a later drop', function () {
    enableBooksModule();
    $user = User::factory()->create();
    $reread = exportedBook('Reread', '1');
    $dropped = exportedBook('Dropped on the second go', '2');
    app(MarkWatched::class)->execute($user, $reread, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(SetMediaStatus::class)->execute($user, $reread, MediaUserStatus::OnHold);
    app(MarkWatched::class)->execute($user, $dropped, WatchedAt::at(CarbonImmutable::parse('2026-01-01 20:00')));
    app(SetMediaStatus::class)->execute($user, $dropped, MediaUserStatus::InProgress);
    app(DropAttempt::class)->execute($user, $dropped, WatchedAt::at(CarbonImmutable::parse('2026-02-01 20:00')));

    $rows = goodreadsRows($user);

    expect(goodreadsRow($rows, 'Reread'))->toMatchArray(['Exclusive Shelf' => 'currently-reading', 'Read Count' => '1', 'Date Read' => '2026/01/01'])
        ->and(goodreadsRow($rows, 'Dropped on the second go'))->toMatchArray(['Exclusive Shelf' => 'read', 'Date Added' => '2026/01/01']);
});

test('a book only rated is read, a book only on a list waits on to-read', function () {
    enableBooksModule();
    $user = User::factory()->create();
    $rated = exportedBook('Rated', '1');
    $listed = exportedBook('Listed', '2');
    app(RateItem::class)->execute($user, $rated, 3);
    app(AddToList::class)->execute($user, app(CreateList::class)->execute($user, 'Sci-fi, classics'), ListTarget::item($listed->id));

    $rows = goodreadsRows($user);

    expect(goodreadsRow($rows, 'Rated'))->toMatchArray(['Exclusive Shelf' => 'read', 'My Rating' => '2', 'Date Read' => ''])
        ->and(goodreadsRow($rows, 'Listed'))->toMatchArray(['Exclusive Shelf' => 'to-read', 'Bookshelves' => 'sci-fi-classics']);
});

test('an author is filed surname first, as Goodreads files them', function (string $author, string $filed) {
    enableBooksModule();
    $user = User::factory()->create();
    app(RateItem::class)->execute($user, exportedBook('Book', '1', ['authors' => [$author]]), 8);

    expect(goodreadsRow(goodreadsRows($user), 'Book')['Author l-f'])->toBe($filed);
})->with([
    'first and last name' => ['Frank Herbert', 'Herbert, Frank'],
    'initials' => ['J.R.R. Tolkien', 'Tolkien, J.R.R.'],
    'one name' => ['Homer', 'Homer'],
    'stray spaces' => [' Frank Herbert ', 'Herbert, Frank'],
]);

test("an edition someone else imported does not stand in for the catalog's ids", function () {
    enableBooksModule();
    $user = User::factory()->create();
    $book = exportedBook('Dune', '44767458', ['isbn' => '9780441172719']);
    app(RateItem::class)->execute($user, $book, 8);
    // Mappings are shared by every account: this one came from another reader's import.
    ExternalIdMapping::factory()->forItem($book, ExternalIdSource::Goodreads, '234225')->create();
    ExternalIdMapping::factory()->forItem($book, ExternalIdSource::Isbn, '9780593099322')->create();

    expect(goodreadsRow(goodreadsRows($user), 'Dune'))->toMatchArray(['Book Id' => '44767458', 'ISBN13' => '9780441172719']);
});

test('the Goodreads export holds only the reader\'s own books', function () {
    enableBooksModule();
    $user = User::factory()->create();
    app(RateItem::class)->execute(User::factory()->create(), exportedBook('Someone else\'s', '1'), 8);
    app(RateItem::class)->execute($user, MediaItem::factory()->movie()->create(['title' => 'A film']), 8);

    expect(goodreadsRows($user))->toBe([]);
});

test('the Goodreads file reads back through the Goodreads import into the same library', function () {
    enableBooksModule();
    $reader = User::factory()->create();
    seedReader($reader);
    $content = $this->actingAs($reader)->get(route('settings.export.download', GoodreadsExport::CODE))->streamedContent();
    Storage::fake('local');
    Storage::disk('local')->put('imports/goodreads.csv', $content);
    // Hardcover places each book by the Goodreads id the file carries.
    $books = MediaItem::query()->where('type', 'book')->get();
    Http::fake(['api.hardcover.app/*' => fn (Request $request) => Http::response(['data' => [
        'book_mappings' => $books
            ->filter(fn (MediaItem $book): bool => ItemMetadata::fromStored($book->metadata)->goodreadsId() === data_get($request->data(), 'variables.id'))
            ->map(fn (MediaItem $book): array => ['book_id' => (int) $book->provider_ref])->values()->all(),
    ]])]);

    $copy = User::factory()->create();
    $taskRun = runImportFile($copy, GoodreadsCsvSource::CODE, 'imports/goodreads.csv', new ImportSelection(
        array_column(GoodreadsShelf::cases(), 'value'),
        ['fantasy-na-zime'],
    ));

    $titleOf = fn (int $id): string => (string) $books->firstOrFail('id', $id)->title;

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($copy->plays()->get()->map(fn ($play) => [$titleOf($play->media_item_id), $play->outcome])->all())->toEqualCanonicalizing([
            ['Hobbit, czyli tam i z powrotem', PlayOutcome::Completed],
            ['Children of Dune', PlayOutcome::Dropped],
        ])
        ->and($copy->plays()->where('outcome', PlayOutcome::Completed)->firstOrFail()->watched_at->toDateString())->toBe('2026-03-01')
        ->and($copy->ratings()->firstOrFail()->rating)->toBe(10)
        ->and($titleOf($copy->mediaStates()->firstOrFail()->media_item_id))->toBe('Dune')
        ->and($copy->mediaStates()->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($copy->watchlist()->items()->get()->map(fn ($item) => $titleOf((int) $item->media_item_id))->all())->toBe(['Dune Messiah'])
        ->and($copy->lists()->where('title', 'fantasy-na-zime')->firstOrFail()->items()->get()->map(fn ($item) => $titleOf((int) $item->media_item_id))->all())
        ->toBe(['Hobbit, czyli tam i z powrotem']);
});
