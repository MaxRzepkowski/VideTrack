<?php

use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Rows\SkipRow;

function csvFile(string $content): string
{
    $path = scratchPath('csv');
    file_put_contents($path, $content);

    return $path;
}

test('rows are keyed by the header, a byte order mark is not part of the first column', function () {
    $csv = new CsvStream(csvFile("\u{FEFF}id,title\n1,Dune\n"), ['id']);

    expect(iterator_to_array($csv->rows(), false))->toBe([['id' => '1', 'title' => 'Dune']]);
});

test('an empty line is nobody\'s entry, and a malformed row is named by its row in a spreadsheet', function () {
    $csv = new CsvStream(csvFile("id,title\n\n1,Dune\n2\n\n"), ['id']);

    $entries = iterator_to_array($csv->entries(), false);

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->toBe(['id' => '1', 'title' => 'Dune'])
        ->and($entries[1])->toBeInstanceOf(SkipRow::class)
        ->and($entries[1] instanceof SkipRow ? $entries[1]->replace : null)->toBe(['row' => 4])
        ->and($csv->count())->toBe(2);
});

test('a quoted field may span lines and stays one row', function () {
    $csv = new CsvStream(csvFile("id,notes\n1,\"first line\nsecond line\"\n2,short\n"), ['id']);

    expect(iterator_to_array($csv->rows(), false))->toBe([
        ['id' => '1', 'notes' => "first line\nsecond line"],
        ['id' => '2', 'notes' => 'short'],
    ]);
});

test('a header without a required column is not the export expected', function () {
    expect((new CsvStream(csvFile("name,title\n1,Dune\n"), ['id']))->isValid())->toBeFalse();
});

test('a note ending in a backslash before its closing quote keeps the rows after it apart', function () {
    // What Python's csv module writes for the note `C:\films\` (Yamtrack's export).
    $csv = new CsvStream(csvFile("id,notes\n1,\"C:\\films\\\"\n2,\"says \"\"hi\"\"\"\n"), ['id']);

    expect(iterator_to_array($csv->rows(), false))->toBe([
        ['id' => '1', 'notes' => 'C:\\films\\'],
        ['id' => '2', 'notes' => 'says "hi"'],
    ]);
});

test('a file inside a ZIP is read where it lies, and a missing one reads as no rows', function () {
    $path = scratchPath('zip', '.zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('likes/films.csv', "Name,Year\nDune,2021\n");
    $zip->close();

    expect(iterator_to_array(CsvStream::inArchive($path, 'likes/films.csv', ['Name'])->rows(), false))->toBe([['Name' => 'Dune', 'Year' => '2021']])
        // Read twice: each pass opens its own handle on the archive.
        ->and(CsvStream::inArchive($path, 'likes/films.csv', ['Name'])->count())->toBe(1)
        ->and(CsvStream::inArchive($path, 'diary.csv', ['Name'])->isValid())->toBeFalse()
        ->and(CsvStream::inArchive(csvFile("Name\nDune\n"), 'diary.csv', ['Name'])->isValid())->toBeFalse();
});

test('an export that opens with a note of its own is read from the header after it', function () {
    $path = scratchPath('zip', '.zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('lists/picks.csv', "Letterboxd list export v7\nDate,Name\n2026-01-01,\"Picks,\nwith a note\"\n\nPosition,Name\n1,Dune\n2\n");
    $zip->close();

    $entries = iterator_to_array(CsvStream::inArchive($path, 'lists/picks.csv', ['Position'], preamble: 4)->entries(), false);

    expect($entries[0])->toBe(['Position' => '1', 'Name' => 'Dune'])
        ->and($entries[1])->toBeInstanceOf(SkipRow::class);
});
