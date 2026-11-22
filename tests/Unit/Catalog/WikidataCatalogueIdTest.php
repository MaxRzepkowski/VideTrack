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
