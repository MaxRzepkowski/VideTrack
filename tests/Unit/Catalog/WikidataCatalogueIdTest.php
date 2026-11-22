<?php

use App\Catalog\MediaKind;
use App\Catalog\Relations\CatalogWork;
use App\Catalog\Wikidata\CatalogueId;

test('the query fragments name every id once, by its own variable', function () {
    expect(CatalogueId::variables(CatalogueId::SCREEN))->toBe('?movie ?show')
        ->and(CatalogueId::patterns('o', CatalogueId::SCREEN))->toBe("OPTIONAL { ?o wdt:P4947 ?movie . }\nOPTIONAL { ?o wdt:P4983 ?show . }")
        ->and(CatalogueId::anyBound(CatalogueId::SCREEN))->toBe('BOUND(?movie) || BOUND(?show)');
});

test('a row becomes a work per id bound to a number, in the catalogue the resolver reads', function () {
    $row = [
        'movie' => ['type' => 'literal', 'value' => '603'],
        'show' => ['type' => 'literal', 'value' => 'not-a-number'],
    ];

    expect(CatalogueId::works($row, CatalogueId::SCREEN))->toEqual([
        'movie:603' => new CatalogWork(MediaKind::Movie, ['tmdb' => '603']),
    ]);
});

test('an id is read as our catalogue writes it, and anything else filed under its property is nothing', function (CatalogueId $id, string $wikidata, ?string $ours) {
    expect($id->ourValue($wikidata))->toBe($ours);
})->with([
    'a comic volume' => [CatalogueId::Comic, '4050-18166', '18166'],
    'a comic character' => [CatalogueId::Comic, '4005-1699', null],
    'a hyphenated ISBN-13' => [CatalogueId::Isbn, '978-0-261-10221-7', '9780261102217'],
    'an ISBN-10, in its 13-digit spelling' => [CatalogueId::Isbn, '0-261-10221-4', '9780261102217'],
    'not an ISBN' => [CatalogueId::Isbn, '12-34', null],
    'a game number' => [CatalogueId::Game, '2262', '2262'],
    'a manga slug' => [CatalogueId::Manga, 'berserk', null],
]);

test('a title of ours is found by the id of its kind and catalogue, an ISBN never', function () {
    expect(CatalogueId::finding(MediaKind::Manga, 'mal'))->toBe(CatalogueId::Manga)
        ->and(CatalogueId::finding(MediaKind::Book, 'goodreads'))->toBe(CatalogueId::Goodreads)
        ->and(CatalogueId::finding(MediaKind::Book, 'isbn'))->toBeNull()
        ->and(CatalogueId::finding(MediaKind::Book, 'hardcover'))->toBeNull()
        ->and(CatalogueId::Comic->wikidataValue('18166'))->toBe('4050-18166')
        ->and(CatalogueId::Game->wikidataValue('2262'))->toBe('2262');
});

test('of a comic\'s Comic Vine numbers only its volume\'s is bound', function () {
    expect(CatalogueId::patterns('o', [CatalogueId::Comic], '_any'))
        ->toBe('OPTIONAL { ?o wdt:P5905 ?comic_any . FILTER(STRSTARTS(?comic_any, "4050-")) }');
});
