<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * TMDB knows two of the fixture's ids; everything else, including a search
 * by name, comes back empty.
 */
function fakeImdbLookups(): void
{
    Http::fake([
        '*/find/tt0133093*' => Http::response(['movie_results' => [['id' => 603]], 'tv_results' => []]),
        '*/find/tt0111161*' => Http::response(['movie_results' => [['id' => 278]], 'tv_results' => []]),
        '*/find/*' => Http::response(['movie_results' => [], 'tv_results' => [], 'tv_episode_results' => []]),
        '*/search/*' => Http::response(['page' => 1, 'results' => [], 'total_pages' => 0, 'total_results' => 0]),
    ]);
}

test('an IMDb export offers its rated and unrated rows apart', function () {
    $this->actingAs(User::factory()->create())
        ->post('/settings/import/imdb/analyze', [
            'file' => new UploadedFile(base_path('tests/Fixtures/transfer/imdb/list.csv'), 'ratings.csv', 'text/csv', null, true),
        ])
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('categories.0.key', 'ratings')
        ->assertJsonPath('categories.0.count', 2)
        ->assertJsonPath('categories.1.key', 'watchlist')
        ->assertJsonPath('categories.1.count', 3);
});

test('an IMDb export brings over ratings and the watchlist by IMDb id', function () {
    fakeImdbLookups();

    $user = User::factory()->create();
    $matrix = MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $shawshank = MediaItem::factory()->movie()->create(['provider_ref' => '278']);

    $taskRun = runImportFile($user, 'imdb', putTransferFixture('imdb', 'list.csv'), new ImportSelection(['ratings', 'watchlist']));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('ratings: 1', 'list items: 1', 'skipped entries: 3')
        ->and($user->ratings()->firstOrFail()->media_item_id)->toBe($matrix->id)
        ->and($user->ratings()->firstOrFail()->rating)->toBe(9)
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$shawshank->id])
        ->and($taskRun->errors)->toBe([
            'The Wonderful Story of Henry Sugar (2023): no match for imdb tt16968450, nor by name.',
            'Breaking Bad: Ozymandias (2013): an episode cannot go on the backlog.',
            'Hotel Transylvania 3 (2018): IMDb title type "Video Game" is not tracked here.',
        ]);

    // Nothing is inferred from a rating: no play appears.
    expect($user->plays()->count())->toBe(0);
});

test('a name search that could not be asked is an outage, not a missing title', function () {
    Http::fake([
        '*/find/*' => Http::response(['movie_results' => [], 'tv_results' => [], 'tv_episode_results' => []]),
        '*/search/*' => Http::response([], 503),
    ]);

    $taskRun = runImportFile(User::factory()->create(), 'imdb', putTransferFixture('imdb', 'list.csv'), new ImportSelection(['ratings', 'watchlist']));

    expect($taskRun->errors)->not->toContain('The Wonderful Story of Henry Sugar (2023): no match for imdb tt16968450, nor by name.')
        ->and(collect($taskRun->errors)->first(fn (string $error): bool => str_starts_with($error, 'The Wonderful Story of Henry Sugar (2023):')))->not->toBeNull();
});

test('an IMDb import takes only the ticked half', function () {
    fakeImdbLookups();

    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    MediaItem::factory()->movie()->create(['provider_ref' => '278']);

    runImportFile($user, 'imdb', putTransferFixture('imdb', 'list.csv'), new ImportSelection(['ratings']));

    expect($user->ratings()->count())->toBe(1)
        ->and($user->watchlist()->items()->count())->toBe(0);
});

test('a file that is not an IMDb export fails the run', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/x.csv', "media_id,source\n1,tmdb\n");

    $taskRun = runImportFile(User::factory()->create(), 'imdb', 'imports/x.csv', new ImportSelection(['ratings']));

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('The file is not an IMDb export.');
});

test('Goodreads is only offered when the books module is on', function () {
    expect(app(ImportSourceRegistry::class)->has('goodreads'))->toBeFalse();
});

test('a Goodreads export places books by ISBN, then by title and author, when Hardcover maps none of their Goodreads ids', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);

    $user = User::factory()->create();
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    $taskRun = runImportFile(
        $user,
        'goodreads',
        putTransferFixture('goodreads', 'library.csv'),
        new ImportSelection(['read', 'currently-reading', 'to-read', 'did-not-finish'], ['fantasy']),
    );

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 1', 'ratings: 1', 'statuses: 1', 'list items: 1', 'skipped entries: 2', '1 title matched by name, not by id.')
        ->and($taskRun->errors)->toBe([
            'Nowhere Book: no match for goodreads 1, nor by name.',
            'Owned Thing: the shelf "owned" has no counterpart here.',
        ]);

    $play = $user->plays()->firstOrFail();

    expect($play->media_item_id)->toBe($hobbit->id)
        ->and($play->watched_at->toDateString())->toBe('2024-03-01')
        ->and((int) $play->amount)->toBe(300)
        ->and($user->ratings()->firstOrFail()->rating)->toBe(10)
        ->and($user->mediaStates()->firstOrFail()->media_item_id)->toBe($dune->id)
        ->and($user->mediaStates()->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->lists()->where('title', 'fantasy')->firstOrFail()->items()->pluck('media_item_id')->all())->toBe([$hobbit->id]);

    // The ISBN-10 edition was asked about in its 13-digit form.
    Http::assertSent(fn (Request $request) => str_contains((string) data_get($request->data(), 'query'), 'query ByIsbn')
        && data_get($request->data(), 'variables.isbns') === ['9780547928227']);
});

test('a Goodreads export places a book by its Goodreads id before asking about its ISBN', function () {
    enableBooksModule();
    // Hardcover maps the Kindle Dune (Goodreads 44767458) to its book; no other id.
    Http::fake(['api.hardcover.app/*' => fn (Request $request) => match (true) {
        str_contains((string) data_get($request->data(), 'query'), 'query ByGoodreads') => Http::response(['data' => [
            'book_mappings' => data_get($request->data(), 'variables.id') === '44767458' ? [['book_id' => 312460]] : [],
        ]]),
        str_contains((string) data_get($request->data(), 'query'), 'query ByIsbn') => Http::response(jsonFixture('hardcover/by_isbn')),
        default => Http::response(['data' => []]),
    }]);

    $user = User::factory()->create();
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    MediaItem::factory()->book()->create(['provider_ref' => '2459845']);

    $taskRun = runImportFile($user, 'goodreads', putTransferFixture('goodreads', 'library.csv'), new ImportSelection(['currently-reading']));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->not->toContain('matched by name')
        ->and($user->mediaStates()->firstOrFail()->media_item_id)->toBe($dune->id);
});

test('a Goodreads export offers the reader\'s own shelves as lists', function () {
    enableBooksModule();

    $this->actingAs(User::factory()->create())
        ->post('/settings/import/goodreads/analyze', [
            'file' => new UploadedFile(base_path('tests/Fixtures/transfer/goodreads/library.csv'), 'goodreads.csv', 'text/csv', null, true),
        ])
        ->assertOk()
        ->assertJsonPath('categories.0.key', 'read')
        ->assertJsonPath('categories.0.count', 1)
        ->assertJsonPath('lists.0.id', 'fantasy')
        ->assertJsonPath('lists.1.id', 'favourites')
        ->assertJsonPath('lists.2.id', 'sci-fi')
        ->assertJsonCount(3, 'lists');
});

test('a book on a shelf with no counterpart here still counts toward the run\'s total', function () {
    enableBooksModule();

    $manifest = app(ImportSourceRegistry::class)->get('goodreads')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/goodreads/library.csv')));

    // Four books: three on shelves the import knows, one "owned" it passes over with a word.
    expect($manifest->total(new ImportSelection(['read', 'currently-reading', 'to-read'])))->toBe(4);
});

test('a CSV row whose columns do not match the header is counted as skipped and named by its row in a spreadsheet', function () {
    Storage::fake('local');
    // An empty line in the middle and one at the end, as a spreadsheet saves them: nobody's entry.
    Storage::disk('local')->put('imports/imdb.csv', "Const,Your Rating,Title,Title Type\n\ntt0133093,9,The Matrix\n\n");

    $run = runImportFile(User::factory()->create(), 'imdb', 'imports/imdb.csv');

    expect($run->summary)->toContain('Processed 1 entry', 'skipped entries: 1')
        ->and($run->errors)->toBe(['Row 3 of the file: its columns do not match the header.']);
});

test('the preview of a CSV file counts a row it cannot read, so the run never goes past its total', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/imdb.csv', "Const,Your Rating,Title,Title Type\ntt0133093,9,The Matrix,Movie\ntt0234215,8,The Matrix Reloaded\n");

    $manifest = app(ImportSourceRegistry::class)->get('imdb')->analyze(ImportInput::file(Storage::disk('local')->path('imports/imdb.csv')));

    expect($manifest->total(new ImportSelection(['ratings', 'watchlist'])))->toBe(2);
});
