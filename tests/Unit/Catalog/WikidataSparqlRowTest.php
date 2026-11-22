<?php

use App\Catalog\Wikidata\SparqlRow;

test('a row variable is read as its value and an entity as its QID; an unbound one is nothing', function () {
    $row = ['s' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q83495'], 'odd' => 'not a binding'];

    expect(SparqlRow::value($row, 's'))->toBe('http://www.wikidata.org/entity/Q83495')
        ->and(SparqlRow::entity($row, 's'))->toBe('Q83495')
        ->and(SparqlRow::value($row, 'odd'))->toBeNull()
        ->and(SparqlRow::entity($row, 'missing'))->toBeNull();
});
