<?php

use App\Integrations\Goodreads\GoodreadsCsvSource;

test('the series note Goodreads appends is not part of the title', function (string $title, string $work) {
    expect(GoodreadsCsvSource::workTitle($title))->toBe($work);
})->with([
    ["The Wise Man's Fear (The Kingkiller Chronicle, #2)", "The Wise Man's Fear"],
    ['Dune (Dune Chronicles, #1)  ', 'Dune'],
    ['Dune (Dune Chronicles #1) ', 'Dune'],
    ['The Hobbit (Annotated Edition)', 'The Hobbit (Annotated Edition)'],
    ['Number #9 Dream', 'Number #9 Dream'],
    ['A (nested (note, #1))', 'A (nested (note, #1))'],
    ['(#1)', ''],
]);

test('a title built to make a pattern backtrack still reads right', function () {
    $title = 'Book ('.str_repeat('#', 200_000).')x';

    expect(GoodreadsCsvSource::workTitle($title))->toBe($title)
        ->and(GoodreadsCsvSource::workTitle('Book ('.str_repeat('#', 200_000).')'))->toBe('Book');
});
