<?php

use App\Catalog\Data\CascadeLeaf;

function leafAt(int ...$path): CascadeLeaf
{
    return new CascadeLeaf(1, null, 'episode', '1', 'Episode', null, null, true, $path);
}

test('leaves order number by number, not as text', function () {
    $leaves = [leafAt(10, 1), leafAt(2, 3), leafAt(2, 10), leafAt(9, 1)];

    usort($leaves, CascadeLeaf::compare(...));

    expect(array_map(fn (CascadeLeaf $leaf): array => $leaf->path, $leaves))->toBe([[2, 3], [2, 10], [9, 1], [10, 1]]);
});

test('a path comes before the longer paths it starts, and after a smaller sibling', function () {
    expect(CascadeLeaf::compare(leafAt(1), leafAt(1, 1)))->toBe(-1)
        ->and(CascadeLeaf::compare(leafAt(1, 1), leafAt(1)))->toBe(1)
        ->and(CascadeLeaf::compare(leafAt(2), leafAt(1, 5)))->toBe(1)
        ->and(CascadeLeaf::compare(leafAt(1, 2), leafAt(1, 2)))->toBe(0);
});
