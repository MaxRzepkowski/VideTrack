<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Every reading shelf and the reader's own shelf. */
function bookWyrmEverything(): ImportSelection
{
    return new ImportSelection(['read', 'reading', 'to-read', 'stopped-reading'], ['Kitchen']);
}

/**
 * A BookWyrm export with only a few of its id columns, as an older instance
 * writes it, put on the fake local disk; its path there.
 */
function putBookWyrmCsv(string ...$rows): string
{
    Storage::fake('local');
    Storage::disk('local')->put('imports/bookwyrm.csv', implode("\n", ['title,author_text,goodreads_key,isbn_10,isbn_13,start_date,finish_date,stopped_date,rating,shelf,shelf_name,shelf_date', ...$rows])."\n");

    return 'imports/bookwyrm.csv';
}

test('BookWyrm is only offered when the books module is on', function () {
    expect(app(ImportSourceRegistry::class)->has('bookwyrm'))->toBeFalse();
});

test('a BookWyrm export offers its reading shelves and the reader\'s own', function () {
    enableBooksModule();

    $manifest = app(ImportSourceRegistry::class)->get('bookwyrm')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/bookwyrm/export.csv')));

    expect(array_map(fn ($category) => [$category->key, $category->count], $manifest->categories))->toBe([
        ['read', 1],
        ['reading', 1],
        ['to-read', 1],
        ['stopped-reading', 0],
    ])
        ->and(array_map(fn ($list) => [$list->id, $list->name, $list->itemCount], $manifest->lists))->toBe([['Kitchen', 'Kitchen', 1]])
        // The book on the reader's own shelf alone is a row of its list.
        ->and($manifest->total(bookWyrmEverything()))->toBe(4);
});

test('a BookWyrm export places books by Goodreads id and ISBN, then by title and first author', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);

    $user = User::factory()->create();
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    putTransferFixture('bookwyrm', 'export.csv');
    $taskRun = runImportFile($user, 'bookwyrm', 'imports/export.csv', bookWyrmEverything());

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 1', 'ratings: 1', 'statuses: 1', 'skipped entries: 2')
        ->and($taskRun->errors)->toBe([
            'Nowhere Book: no match for an entry without ids, nor by name.',
            // A book on the reader's own shelf alone is that list's, not a skip.
            'Kitchen Notes: no match for an entry without ids, nor by name.',
        ]);

    expect($user->plays()->where('media_item_id', $hobbit->id)->get()->map(fn ($play) => $play->watched_at->toDateString())->all())->toBe(['2024-03-01'])
        // 4.5 stars is 9 on the ten-point scale.
        ->and($user->ratings()->where('media_item_id', $hobbit->id)->value('rating'))->toBe(9)
        ->and($user->mediaStates()->where('media_item_id', $dune->id)->firstOrFail()->status)->toBe(MediaUserStatus::InProgress);

    // The ISBN-10 edition was asked about in its 13-digit form, Dune by its Goodreads id; the ASIN never.
    Http::assertSent(fn (Request $request) => data_get($request->data(), 'variables.isbns') === ['9780547928227']);
    Http::assertSent(fn (Request $request) => data_get($request->data(), 'variables.ids') === ['234225']);
    Http::assertNotSent(fn (Request $request) => str_contains((string) json_encode($request->data()), 'B0B3Y8WL2L'));
});

test('a book stopped reading is dropped on the day it was stopped, and one finished on an own shelf is read', function () {
    enableBooksModule();
    fakeHardcover(['Search' => 'search']);

    $user = User::factory()->create();
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    $path = putBookWyrmCsv(
        // On a shelf of the reader's own, the read-through says it was stopped.
        'Dune,Frank Herbert,,,,2025-01-05,,2025-02-10,,sci-fi-4,Sci-fi,2025-02-11',
        '"The Hobbit, or There and Back Again",J.R.R. Tolkien,,,,,2023-06-01,,,favourites-3,Favourites,2023-06-02',
    );
    $source = app(ImportSourceRegistry::class)->get('bookwyrm');
    $selection = new ImportSelection(['read', 'stopped-reading'], ['Favourites']);
    // Dune is on a shelf of the reader's own too, but that list was not picked.

    expect(array_map(fn ($category) => [$category->key, $category->count], $source->analyze(ImportInput::file(Storage::disk('local')->path($path)))->categories))
        ->toBe([['read', 1], ['reading', 0], ['to-read', 0], ['stopped-reading', 1]]);

    $taskRun = runImportFile($user, 'bookwyrm', $path, $selection);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($user->plays()->where('media_item_id', $hobbit->id)->get()->map(fn ($play) => $play->watched_at->toDateString())->all())->toBe(['2023-06-01'])
        ->and($user->lists()->where('title', 'Favourites')->firstOrFail()->items()->pluck('media_item_id')->all())->toBe([$hobbit->id])
        ->and($user->plays()->where('media_item_id', $dune->id)->where('outcome', PlayOutcome::Dropped->value)->firstOrFail()->watched_at->toDateString())->toBe('2025-02-10');
});

test('importing the same BookWyrm export twice adds nothing', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);

    $user = User::factory()->create();
    MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    putTransferFixture('bookwyrm', 'export.csv');
    runImportFile($user, 'bookwyrm', 'imports/export.csv', bookWyrmEverything());
    putTransferFixture('bookwyrm', 'export.csv');
    $again = runImportFile($user, 'bookwyrm', 'imports/export.csv', bookWyrmEverything());

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'statuses: 0', 'list items: 0');
});

test('a StoryGraph export is not a BookWyrm export', function () {
    enableBooksModule();

    $manifest = app(ImportSourceRegistry::class)->get('bookwyrm')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/storygraph/library.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a BookWyrm export.');
});
