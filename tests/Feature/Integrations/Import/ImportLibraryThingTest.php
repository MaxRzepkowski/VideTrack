<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;

/*
 * LibraryThing is exercised on the shape in tests/Fixtures/transfer/librarything
 * (see the README there).
 */

function libraryThingEverything(): ImportSelection
{
    return new ImportSelection(['read', 'reading', 'wanted', 'favorites', 'rated', 'library'], ['Book club']);
}

test('LibraryThing is only offered when the books module is on', function () {
    expect(app(ImportSourceRegistry::class)->has('librarything'))->toBeFalse();
});

test('a LibraryThing export offers what its collections say and the reader\'s own collections', function () {
    enableBooksModule();

    $manifest = app(ImportSourceRegistry::class)->get('librarything')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/librarything/library.tsv')));

    expect($manifest->valid)->toBeTrue()
        ->and(array_map(fn (ManifestCategory $category) => [$category->key, $category->count], $manifest->categories))->toBe([
            ['read', 2],
            ['reading', 1],
            ['wanted', 1],
            ['favorites', 1],
            ['rated', 1],
            ['library', 2],
        ])
        ->and(array_map(fn ($list) => [$list->id, $list->itemCount], $manifest->lists))->toBe([['Book club', 1]]);
});

test('a LibraryThing export places books by ISBN, then by title and author', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);

    $user = User::factory()->create();
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    $taskRun = runImportFile($user, 'librarything', putTransferFixture('librarything', 'library.tsv'), libraryThingEverything());

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 1', 'ratings: 1', 'favorites: 1', 'statuses: 1')
        ->and($taskRun->errors)->toBe([
            'Rayuela: no match for an entry without ids, nor by name.',
            'Old Book: no match for an entry without ids, nor by name.',
            'LibraryThing book 1005: no title.',
        ]);

    $play = $user->plays()->where('media_item_id', $hobbit->id)->firstOrFail();

    expect($play->watched_at->toDateString())->toBe('2023-05-08')
        ->and($user->ratings()->where('media_item_id', $hobbit->id)->value('rating'))->toBe(9)
        ->and($user->favorites()->pluck('media_item_id')->all())->toBe([$hobbit->id])
        ->and($user->lists()->where('title', 'LibraryThing')->firstOrFail()->items()->pluck('media_item_id')->all())->toBe([$hobbit->id])
        ->and($user->mediaStates()->where('media_item_id', $dune->id)->value('status'))->toBe(MediaUserStatus::InProgress);
});

test('importing the same LibraryThing export twice adds nothing', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);

    $user = User::factory()->create();
    MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    runImportFile($user, 'librarything', putTransferFixture('librarything', 'library.tsv'), libraryThingEverything());
    $again = runImportFile($user, 'librarything', putTransferFixture('librarything', 'library.tsv'), libraryThingEverything());

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'favorites: 0', 'statuses: 0', 'list items: 0');
});

test('a Goodreads export is not a LibraryThing one', function () {
    enableBooksModule();

    $manifest = app(ImportSourceRegistry::class)->get('librarything')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/goodreads/library.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a LibraryThing export as tab-delimited text.');
});
