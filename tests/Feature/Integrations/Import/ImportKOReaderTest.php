<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Pdo\Sqlite;

/** KOReader's statistics tables as its statistics plugin creates them (schema 20221111). */
const KOREADER_SCHEMA = <<<'SQL'
    CREATE TABLE book (id integer PRIMARY KEY autoincrement, title text, authors text, notes integer, last_open integer, highlights integer, pages integer, series text, language text, md5 text, total_read_time integer, total_read_pages integer);
    CREATE UNIQUE INDEX book_title_authors_md5 ON book(title, authors, md5);
    CREATE TABLE page_stat_data (id_book integer, page integer NOT NULL DEFAULT 0, start_time integer NOT NULL DEFAULT 0, duration integer NOT NULL DEFAULT 0, total_pages integer NOT NULL DEFAULT 0, UNIQUE (id_book, page, start_time), FOREIGN KEY(id_book) REFERENCES book(id));
    SQL;

/**
 * A statistics file, put on the fake local disk; its path there. The
 * Hobbit was read to its end in a smaller font than it began in, Dune is
 * half read, a third book was only opened.
 */
function putKOReaderStatistics(): string
{
    $path = scratchPath('statistics', '.sqlite3');
    $db = new Sqlite("sqlite:{$path}");
    $db->exec(KOREADER_SCHEMA);
    $db->exec(<<<'SQL'
        INSERT INTO book (id, title, authors, pages, md5, last_open) VALUES
            (1, 'The Hobbit, or There and Back Again', 'J.R.R. Tolkien', 310, 'a1', 1709300000),
            (2, 'Dune', 'Frank Herbert' || char(10) || 'Brian Herbert', 600, 'b2', 1736100000),
            (3, 'Opened Only', 'Somebody', 200, 'c3', 1700000000);
        INSERT INTO page_stat_data (id_book, page, start_time, duration, total_pages) VALUES
            (1, 1, 1708430000, 60, 280),
            (1, 140, 1708900000, 60, 280),
            (1, 300, 1709290000, 60, 310),
            (2, 1, 1736080000, 60, 600),
            (2, 300, 1736100000, 60, 600);
        SQL);

    Storage::fake('local');
    Storage::disk('local')->put('imports/statistics.sqlite3', (string) file_get_contents($path));

    return 'imports/statistics.sqlite3';
}

test('KOReader is only offered when the books module is on', function () {
    expect(app(ImportSourceRegistry::class)->has('koreader'))->toBeFalse();
});

test('KOReader statistics offer the books read to the end and those begun', function () {
    enableBooksModule();

    $path = putKOReaderStatistics();
    $manifest = app(ImportSourceRegistry::class)->get('koreader')->analyze(ImportInput::file(Storage::disk('local')->path($path)));

    expect(array_map(fn ($category) => [$category->key, $category->count], $manifest->categories))->toBe([['read', 1], ['reading', 1]])
        ->and($manifest->total(new ImportSelection(['read', 'reading'])))->toBe(3);
});

test('a book read to its end is read on the day its end was reached, one begun is in progress', function () {
    enableBooksModule();
    fakeHardcover(['Search' => 'search']);

    $user = User::factory()->create();
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);

    $taskRun = runImportFile($user, 'koreader', putKOReaderStatistics(), new ImportSelection(['read', 'reading']));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['Opened Only: opened, but no page read.'])
        // Page 300 of 310 in the layout it was read in; 2024-03-01 13:33 UTC.
        ->and($user->plays()->where('media_item_id', $hobbit->id)->firstOrFail()->watched_at->toDateString())->toBe('2024-03-01')
        ->and($user->mediaStates()->where('media_item_id', $dune->id)->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->plays()->where('media_item_id', $dune->id)->exists())->toBeFalse();

    $again = runImportFile($user, 'koreader', putKOReaderStatistics(), new ImportSelection(['read', 'reading']));

    expect($again->summary)->toContain('plays: 0', 'statuses: 0');
});

test('a database that is not KOReader\'s is refused, as is a file that is no database', function () {
    enableBooksModule();
    $source = app(ImportSourceRegistry::class)->get('koreader');

    $other = scratchPath('other', '.sqlite3');
    (new Sqlite("sqlite:{$other}"))->exec('CREATE TABLE books (id INTEGER PRIMARY KEY)');

    expect($source->analyze(ImportInput::file($other))->error)->toBe("The file is not KOReader's reading statistics.")
        ->and($source->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/goodreads/library.csv')))->valid)->toBeFalse();
});

test('statistics KOReader keeps in WAL mode are read without leaving files beside the upload', function () {
    enableBooksModule();
    $directory = scratchDirectory('koreader');
    $path = "{$directory}/statistics.sqlite3";
    $db = new Sqlite("sqlite:{$path}");
    $db->exec('PRAGMA journal_mode=WAL;'.KOREADER_SCHEMA."INSERT INTO book (id, title, authors) VALUES (1, 'Dune', 'Frank Herbert'); INSERT INTO page_stat_data VALUES (1, 1, 1736080000, 60, 600);");
    unset($db);
    array_map(unlink(...), array_diff(glob("{$directory}/*") ?: [], [$path]));

    $manifest = app(ImportSourceRegistry::class)->get('koreader')->analyze(ImportInput::file($path));

    expect($manifest->categories[1]->count)->toBe(1)
        ->and(glob("{$directory}/*"))->toBe([$path]);
});
