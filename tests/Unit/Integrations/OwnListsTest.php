<?php

use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestList;
use App\Integrations\Import\OwnLists;

test('own lists are offered by name with how many titles are on each', function () {
    $lists = new OwnLists;
    $lists->count('Fantasy');
    $lists->count(' Fantasy ');
    $lists->count('Book club');
    $lists->count('');

    expect(array_map(fn (ManifestList $list) => [$list->id, $list->itemCount, $list->isPublic], $lists->offered()))->toBe([
        ['Fantasy', 2, false],
        ['Book club', 1, false],
    ]);
});

test('a long list name goes by a key a selection can carry back', function () {
    $name = str_repeat('a', ImportSelection::KEY_LENGTH + 10);
    $lists = new OwnLists;
    $lists->count($name);
    $picked = OwnLists::picked(new ImportSelection([], [$lists->offered()[0]->id]));

    expect(mb_strlen($lists->offered()[0]->id))->toBe(ImportSelection::KEY_LENGTH)
        ->and($picked)->toHaveKey(OwnLists::key($name));
});
