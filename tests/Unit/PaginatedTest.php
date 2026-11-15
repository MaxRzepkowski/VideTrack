<?php

use App\Catalog\Data\Paginated;

test('a listing starts at page one and counts nothing below zero', function (int $page, int $totalPages, int $total) {
    expect(fn () => new Paginated([], $page, $totalPages, $total))->toThrow(InvalidArgumentException::class);
})->with([
    'page zero' => [0, 1, 0],
    'no pages' => [1, 0, 0],
    'negative total' => [1, 1, -1],
]);

test('an empty listing is one empty page', function () {
    expect(new Paginated([], 1, 1, 0))->toMatchObject(['page' => 1, 'totalPages' => 1, 'total' => 0]);
});
