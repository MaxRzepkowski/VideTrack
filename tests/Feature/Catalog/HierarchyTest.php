<?php

use App\Catalog\Data\CascadeLeaf;
use App\Catalog\Hierarchy;
use App\Catalog\HierarchyCycle;
use App\Models\MediaItem;
use Illuminate\Support\Facades\DB;

/**
 * @return array{show: MediaItem, specials: MediaItem, season: MediaItem, special: MediaItem, first: MediaItem, unaired: MediaItem}
 */
function showTree(): array
{
    $show = MediaItem::factory()->show()->create();
    $specials = MediaItem::factory()->season($show, 0)->create();
    $season = MediaItem::factory()->season($show, 1)->create();

    return [
        'show' => $show,
        'specials' => $specials,
        'season' => $season,
        'special' => MediaItem::factory()->episode($specials, 1)->create(),
        'first' => MediaItem::factory()->episode($season, 1)->create(),
        'unaired' => MediaItem::factory()->episode($season, 2, null)->create(),
    ];
}

test('a climb lists the item and every ancestor up to the root', function () {
    $tree = showTree();

    $chain = app(Hierarchy::class)->ancestry($tree['first']);

    expect(array_map(fn (MediaItem $item): int => $item->id, $chain))
        ->toBe([$tree['first']->id, $tree['season']->id, $tree['show']->id])
        ->and(app(Hierarchy::class)->rootOf($tree['show'])->id)->toBe($tree['show']->id);
});

test('a climb through loaded parents reaches the root without a query', function () {
    $tree = showTree();
    $episode = MediaItem::query()->with('parent.parent')->findOrFail($tree['first']->id);

    DB::enableQueryLog();

    expect(app(Hierarchy::class)->loadedRootOf($episode)->id)->toBe($tree['show']->id)
        ->and(DB::getQueryLog())->toBe([])
        ->and(app(Hierarchy::class)->loadedRootOf($tree['show'])->id)->toBe($tree['show']->id);
});

test('several trees map every item to its root in one descent', function () {
    $tree = showTree();
    $movie = MediaItem::factory()->movie()->create();

    $rootOf = app(Hierarchy::class)->treesOf([$tree['show']->id, $movie->id]);

    expect($rootOf)->toHaveCount(7)
        ->and($rootOf[$tree['unaired']->id])->toBe($tree['show']->id)
        ->and($rootOf[$tree['special']->id])->toBe($tree['show']->id)
        ->and($rootOf[$movie->id])->toBe($movie->id);
});

test('a batch of items maps to its roots in one climb', function () {
    $tree = showTree();
    $movie = MediaItem::factory()->movie()->create();

    expect(app(Hierarchy::class)->rootIdsByItem([$tree['first']->id, $tree['season']->id, $movie->id]))->toBe([
        $movie->id => $movie->id,
        $tree['season']->id => $tree['show']->id,
        $tree['first']->id => $tree['show']->id,
    ])->and(app(Hierarchy::class)->rootIds([$tree['first']->id, $tree['special']->id])->all())->toBe([$tree['show']->id]);
});

test('a cascade skips the specials, the subtree does not', function () {
    $tree = showTree();
    $hierarchy = app(Hierarchy::class);

    expect($hierarchy->cascadeIds($tree['show'])->sort()->values()->all())
        ->toBe(collect([$tree['show'], $tree['season'], $tree['first'], $tree['unaired']])->pluck('id')->sort()->values()->all())
        ->and($hierarchy->subtreeIds($tree['show'])->count())->toBe(6)
        ->and($hierarchy->playableLeaves($tree['show'])->pluck('id')->sort()->values()->all())
        ->toBe([$tree['first']->id, $tree['unaired']->id])
        // Asked for by name, the specials season reaches its own episodes.
        ->and($hierarchy->playableLeaves($tree['specials'])->pluck('id')->all())->toBe([$tree['special']->id]);
});

test('cascade leaves come in hierarchy order without the specials, for a show and its own season alike', function () {
    $show = MediaItem::factory()->show()->create();
    // Stored out of order: the leaves are ordered by number, not by row.
    $second = MediaItem::factory()->season($show, 2)->create();
    $first = MediaItem::factory()->season($show, 1)->create();
    $specials = MediaItem::factory()->season($show, 0)->create();
    MediaItem::factory()->episode($specials, 1)->create();
    $s2e1 = MediaItem::factory()->episode($second, 1)->create();
    $s1e2 = MediaItem::factory()->episode($first, 2, null)->create();
    $s1e1 = MediaItem::factory()->episode($first, 1)->create();

    DB::enableQueryLog();
    $leaves = app(Hierarchy::class)->cascadeLeaves(collect([$show, $first]));

    expect(array_map(fn (CascadeLeaf $leaf): int => $leaf->id, $leaves[$show->id]))->toBe([$s1e1->id, $s1e2->id, $s2e1->id])
        ->and(array_map(fn (CascadeLeaf $leaf): array => $leaf->path, $leaves[$show->id]))->toBe([[1, 1], [1, 2], [2, 1]])
        ->and(array_map(fn (CascadeLeaf $leaf): bool => $leaf->aired, $leaves[$show->id]))->toBe([true, false, true])
        ->and(array_map(fn (CascadeLeaf $leaf): array => $leaf->path, $leaves[$first->id]))->toBe([[1], [2]])
        // The season's episodes are read once, though both containers reach them.
        ->and(DB::getQueryLog())->toHaveCount(2);
});

test('a cascade descent through a parent cycle fails loudly', function () {
    $tree = showTree();
    $tree['season']->forceFill(['parent_id' => $tree['first']->id])->save();
    $tree['first']->forceFill(['type' => 'season'])->save();

    app(Hierarchy::class)->cascadeLeaves(collect([$tree['season']->refresh()]));
})->throws(HierarchyCycle::class);

test('a parent cycle fails loudly instead of spinning', function () {
    $tree = showTree();
    $tree['show']->forceFill(['parent_id' => $tree['first']->id])->save();

    app(Hierarchy::class)->rootIdsByItem([$tree['season']->id]);
})->throws(HierarchyCycle::class);

test('an episode has aired by the end of the day it airs', function () {
    $tree = showTree();

    expect($tree['first']->hasAired(today()))->toBeTrue()
        ->and($tree['unaired']->hasAired(today()))->toBeFalse()
        ->and(MediaItem::factory()->episode($tree['season'], 3, today()->toDateString())->create()->hasAired(today()))->toBeTrue()
        ->and(MediaItem::factory()->episode($tree['season'], 4, today()->addDay()->toDateString())->create()->hasAired(today()))->toBeFalse();
});
