<?php

use App\Catalog\TitleStore;
use App\Models\MediaItem;
use App\Models\TitleRefresh;
use App\Tasks\CatalogRefresh\RefreshQueue;
use App\Tasks\CatalogRefresh\RefreshReason;
use Illuminate\Support\Facades\Http;

function refreshQueue(): RefreshQueue
{
    return app(RefreshQueue::class);
}

/**
 * @return list<int>
 */
function handedOut(): array
{
    return refreshQueue()->take(100, [])->pluck('media_item_id')->all();
}

test('roots are handed out most urgent first, then by how long they waited', function () {
    [$cycle, $changed, $older, $active] = MediaItem::factory()->movie()->count(4)->create()->all();

    refreshQueue()->markDue([$cycle->id], RefreshReason::Cycle);
    refreshQueue()->markDue([$older->id], RefreshReason::Active);
    $this->travel(1)->minute();
    refreshQueue()->markDue([$changed->id], RefreshReason::Changed);
    refreshQueue()->markDue([$active->id], RefreshReason::Active);

    expect(handedOut())->toBe([$older->id, $active->id, $changed->id, $cycle->id]);
});

test('marking a waiting root again never lowers its reason nor makes it wait longer', function () {
    $film = MediaItem::factory()->movie()->create();
    refreshQueue()->markDue([$film->id], RefreshReason::Active);
    $since = now()->toImmutable();

    $this->travel(1)->hour();
    refreshQueue()->markDue([$film->id], RefreshReason::Cycle);

    $row = TitleRefresh::query()->findOrFail($film->id);

    expect($row->reason)->toBe(RefreshReason::Active)
        ->and($row->due_at?->equalTo($since))->toBeTrue();
});

test('a root put off is not handed out until its time, and marking it again brings it back now', function () {
    $film = MediaItem::factory()->movie()->create();
    refreshQueue()->markDue([$film->id], RefreshReason::Cycle);
    refreshQueue()->postpone($film->id, now()->addDay());

    expect(handedOut())->toBe([]);

    refreshQueue()->markDue([$film->id], RefreshReason::Active);

    expect(handedOut())->toBe([$film->id])
        ->and(TitleRefresh::query()->findOrFail($film->id)->reason)->toBe(RefreshReason::Active);
});

test('a root never asked waits from the day it was stored', function () {
    $asked = MediaItem::factory()->movie()->create(['created_at' => now()->subYears(2)]);
    $neverAsked = MediaItem::factory()->movie()->create(['created_at' => now()->subYear()]);
    TitleRefresh::query()->create(['media_item_id' => $asked->id, 'asked_at' => now()->subMonth()]);

    expect(refreshQueue()->longestUnasked('tmdb', 'movie', 2))->toBe([$neverAsked->id, $asked->id]);
});

test('storing or refreshing a title records that its provider answered', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $film = app(TitleStore::class)->persist('movie', '603', 'en');

    expect(TitleRefresh::query()->findOrFail($film->id)->asked_at?->equalTo(now()))->toBeTrue();

    refreshQueue()->markDue([$film->id], RefreshReason::Cycle);
    $this->travel(1)->hour();
    app(TitleStore::class)->refresh($film, 'en');

    $row = TitleRefresh::query()->findOrFail($film->id);

    expect($row->asked_at?->equalTo(now()))->toBeTrue()
        ->and($row->due_at)->toBeNull()
        ->and($row->reason)->toBeNull();
});

test('a root of a type this instance does not serve is never handed out', function () {
    // Comics without a ComicVine key: the module is off, its rows stay.
    $comic = MediaItem::factory()->comic()->create();
    refreshQueue()->markDue([$comic->id], RefreshReason::Active);

    expect(handedOut())->toBe([])
        ->and(refreshQueue()->hasDue([]))->toBeFalse();
});

test('a stopped provider keeps its roots waiting while the others are handed out', function () {
    enableComicsModule();
    $film = MediaItem::factory()->movie()->create();
    $comic = MediaItem::factory()->comic()->create();
    refreshQueue()->markDue([$film->id, $comic->id], RefreshReason::Active);

    expect(refreshQueue()->take(10, ['tmdb'])->pluck('media_item_id')->all())->toBe([$comic->id])
        ->and(refreshQueue()->hasDue(['tmdb', 'comicvine']))->toBeFalse();
});
