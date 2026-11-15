<?php

use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\CollectionRelations;

test('a collection keeps what it is tied to as stored, and reads back only what still reads', function () {
    $relations = new CollectionRelations(
        parent: new CollectionLink('183', 'Tomb Raider'),
        spinOffSeries: [new CollectionLink('9400', 'Lara Croft')],
    );
    $stored = $relations->toStored();
    $stored['subseries'][] = ['name' => 'no ref'];

    expect(CollectionRelations::fromStored($stored))->toEqual($relations)
        ->and((new CollectionRelations)->isEmpty())->toBeTrue();
});
