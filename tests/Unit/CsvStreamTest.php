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
