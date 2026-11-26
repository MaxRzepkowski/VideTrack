<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Calibre\CalibreLibrary;
use App\Integrations\Calibre\Data\CalibreBook;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Pdo\Sqlite;

/**
 * A Calibre library database on the fake local disk, with the tables (and
 * the columns of them the import reads) Calibre's own schema gives it; its
 * path there. Calibre writes timestamps with a UTC offset, and 0101-01-01
 * for a publication date nobody set.
 */
function putCalibreLibrary(): string
{
    $path = scratchPath('calibre', '.db');
    $db = new Sqlite("sqlite:{$path}");
    $db->exec(<<<'SQL'
        CREATE TABLE books (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT 'Unknown', sort TEXT, timestamp TIMESTAMP, pubdate TIMESTAMP, series_index REAL NOT NULL DEFAULT 1.0, author_sort TEXT, isbn TEXT DEFAULT '', lccn TEXT DEFAULT '', path TEXT NOT NULL DEFAULT '', flags INTEGER NOT NULL DEFAULT 1, uuid TEXT, has_cover BOOL DEFAULT 0, last_modified TIMESTAMP);
        CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL, sort TEXT, link TEXT NOT NULL DEFAULT '');
        CREATE TABLE books_authors_link (id INTEGER PRIMARY KEY, book INTEGER NOT NULL, author INTEGER NOT NULL);
        CREATE TABLE identifiers (id INTEGER PRIMARY KEY, book INTEGER NOT NULL, type TEXT NOT NULL DEFAULT 'isbn', val TEXT NOT NULL);
        CREATE TABLE ratings (id INTEGER PRIMARY KEY, rating INTEGER CHECK(rating > -1 AND rating < 11));
        CREATE TABLE books_ratings_link (id INTEGER PRIMARY KEY, book INTEGER NOT NULL, rating INTEGER NOT NULL);

        INSERT INTO books (id, title, timestamp, pubdate, path) VALUES
            (1, 'The Hobbit, or There and Back Again', '2021-04-02 18:30:00.123456+00:00', '1937-09-21 00:00:00+00:00', 'J.R.R. Tolkien/The Hobbit (1)'),
            (2, 'Dune', '2022-06-10 09:00:00+00:00', '0101-01-01 00:00:00+00:00', 'Frank Herbert/Dune (2)'),
            (3, 'Nowhere Book', '2023-01-01 12:00:00+00:00', '2001-01-01 00:00:00+00:00', 'Nobody/Nowhere Book (3)'),
            (4, '', '2023-01-01 12:00:00+00:00', '2001-01-01 00:00:00+00:00', 'Unknown (4)');
        INSERT INTO authors (id, name, sort) VALUES (1, 'J.R.R. Tolkien', 'Tolkien, J.R.R.'), (2, 'Frank Herbert', 'Herbert, Frank'), (3, 'Brian Herbert', 'Herbert, Brian'), (4, 'Nobody', 'Nobody');
        INSERT INTO books_authors_link (id, book, author) VALUES (1, 1, 1), (2, 2, 2), (3, 2, 3), (4, 3, 4);
        INSERT INTO identifiers (book, type, val) VALUES (1, 'isbn', '9780547928227'), (1, 'amazon', 'B007978NPG');
        INSERT INTO ratings (id, rating) VALUES (1, 8), (2, 0);
        INSERT INTO books_ratings_link (book, rating) VALUES (1, 1), (2, 2);
        SQL);

    Storage::fake('local');
    Storage::disk('local')->put('imports/metadata.db', (string) file_get_contents($path));

    return 'imports/metadata.db';
}

test('Calibre is only offered when the books module is on', function () {
    expect(app(ImportSourceRegistry::class)->has('calibre'))->toBeFalse();
});

test('a Calibre book keeps the year Calibre wrote, whatever zone the instance keeps time in', function () {
    instanceTimezone('America/Los_Angeles');

    $path = putCalibreLibrary();
    $library = CalibreLibrary::open(Storage::disk('local')->path($path)) ?? throw new LogicException('Not a Calibre library.');
    $years = array_map(fn (CalibreBook $book): ?int => $book->year, iterator_to_array($library->books(), false));

    // Midnight UTC on New Year's Day is still the year before in Los Angeles.
    expect($years)->toBe([1937, null, 2001, 2001]);
});

test('a Calibre library offers its books and their ratings', function () {
    enableBooksModule();

    $path = putCalibreLibrary();
    $manifest = app(ImportSourceRegistry::class)->get('calibre')->analyze(ImportInput::file(Storage::disk('local')->path($path)));

    // A zero is Calibre's "unrated".
    expect(array_map(fn (ManifestCategory $category) => [$category->key, $category->count], $manifest->categories))->toBe([
        ['library', 4],
        ['ratings', 1],
    ]);
});

test('a Calibre library comes over as a list with its ratings, books placed by ISBN, then by title and author', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);

    $user = User::factory()->create();
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    $taskRun = runImportFile($user, 'calibre', putCalibreLibrary(), new ImportSelection(['library', 'ratings']));

    $list = $user->lists()->where('title', 'Calibre')->firstOrFail();

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe([
            'Nowhere Book: no match for an entry without ids, nor by name.',
            'Calibre book 4: no title.',
        ])
        ->and($list->items()->orderBy('id')->pluck('media_item_id')->all())->toBe([$hobbit->id, $dune->id])
        ->and($list->items()->where('media_item_id', $hobbit->id)->value('created_at')?->toDateString())->toBe('2021-04-02')
        ->and($user->ratings()->pluck('rating', 'media_item_id')->all())->toBe([$hobbit->id => 8])
        ->and($user->plays()->count())->toBe(0);
});

test('a database that is not a Calibre library is refused, as is a file that is no database', function () {
    enableBooksModule();
    $source = app(ImportSourceRegistry::class)->get('calibre');

    $other = scratchPath('other', '.db');
    (new Sqlite("sqlite:{$other}"))->exec('CREATE TABLE books (id INTEGER PRIMARY KEY)');

    expect($source->analyze(ImportInput::file($other))->error)->toBe('The file is not a Calibre library database (metadata.db).')
        ->and($source->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/goodreads/library.csv')))->valid)->toBeFalse();
});
