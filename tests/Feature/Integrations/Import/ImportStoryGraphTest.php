<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Every status, every tag. */
function storyGraphEverything(): ImportSelection
{
    return new ImportSelection(['read', 'currently-reading', 'paused', 'to-read', 'did-not-finish'], ['fantasy', 'owned-maybe']);
}

test('StoryGraph is only offered when the books module is on', function () {
    expect(app(ImportSourceRegistry::class)->has('storygraph'))->toBeFalse();
});

test('a StoryGraph export offers its statuses and the reader\'s tags', function () {
    enableBooksModule();

    $manifest = app(ImportSourceRegistry::class)->get('storygraph')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/storygraph/library.csv')));

    expect(array_map(fn ($category) => [$category->key, $category->count], $manifest->categories))->toBe([
        ['read', 1],
        ['currently-reading', 0],
        ['paused', 1],
        ['to-read', 1],
        ['did-not-finish', 0],
    ])
        ->and(array_map(fn ($list) => [$list->id, $list->itemCount], $manifest->lists))->toBe([
            ['fantasy', 1], ['favourites', 1], ['sci-fi', 1], ['owned-maybe', 1],
        ])
        // The book with tags alone is a row of the run too.
        ->and($manifest->total(storyGraphEverything()))->toBe(6);
});

test('a StoryGraph export places books by ISBN, then by title and first author', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);

    $user = User::factory()->create();
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    putTransferFixture('storygraph', 'library.csv');
    $taskRun = runImportFile($user, 'storygraph', 'imports/library.csv', storyGraphEverything());

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 1', 'ratings: 1', 'statuses: 1', 'list items: 1', 'skipped entries: 3', '1 title matched by name, not by id.')
        ->and($taskRun->errors)->toBe([
            'Nowhere Book: no match for an entry without ids, nor by name.',
            'Owned Thing: no read status, only tags.',
            // The tag outlives a book nobody can place.
            'Owned Thing: no match for an entry without ids, nor by name.',
        ]);

    // Read twice, dated once: one play, on the last day read.
    expect($user->plays()->where('media_item_id', $hobbit->id)->get()->map(fn ($play) => $play->watched_at->toDateString())->all())->toBe(['2024-03-01'])
        // 4.25 stars is 8.5 on the ten-point scale.
        ->and($user->ratings()->where('media_item_id', $hobbit->id)->value('rating'))->toBe(9)
        ->and($user->mediaStates()->where('media_item_id', $dune->id)->firstOrFail()->status)->toBe(MediaUserStatus::OnHold)
        ->and($user->lists()->where('title', 'fantasy')->firstOrFail()->items()->pluck('media_item_id')->all())->toBe([$hobbit->id]);

    // The ISBN-10 edition was asked about in its 13-digit form; the ASIN never.
    Http::assertSent(fn (Request $request) => data_get($request->data(), 'variables.isbns') === ['9780547928227']);
    Http::assertNotSent(fn (Request $request) => str_contains((string) json_encode($request->data()), 'B0B3Y8WL2L'));
});

test('importing the same StoryGraph export twice adds nothing', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);

    $user = User::factory()->create();
    MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    putTransferFixture('storygraph', 'library.csv');
    runImportFile($user, 'storygraph', 'imports/library.csv', storyGraphEverything());
    putTransferFixture('storygraph', 'library.csv');
    $again = runImportFile($user, 'storygraph', 'imports/library.csv', storyGraphEverything());

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'statuses: 0', 'list items: 0');
});

test('a Goodreads export is not a StoryGraph export', function () {
    enableBooksModule();

    $manifest = app(ImportSourceRegistry::class)->get('storygraph')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/goodreads/library.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a StoryGraph export.');
});
