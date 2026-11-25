<?php

use App\Catalog\ItemMetadata;
use App\Catalog\ItemNotFoundException;
use App\Catalog\TitleStore;
use App\Models\MediaItem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Http;

function titleStore(): TitleStore
{
    return app(TitleStore::class);
}

function fakeShowTree(): void
{
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
    ]);
}

test('a movie persists standalone with details mapped to columns', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $item = titleStore()->persist('movie', '603', 'en');

    expect($item->type)->toBe('movie')
        ->and($item->provider_ref)->toBe('603')
        ->and($item->parent_id)->toBeNull()
        ->and($item->title)->toBe('The Matrix')
        ->and($item->runtime)->not->toBeNull()
        ->and($item->metadata)->toHaveKey('genres');

    expect(MediaItem::count())->toBe(1);
});

test('a movie denormalises the earlier of its digital and disc dates', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $item = titleStore()->persist('movie', '603', 'en');

    // Fixture: digital 1999-08-15 (RO), disc 1999-11-02 (US). The column is
    // what the calendar filters a range on; the rows stay in metadata.
    expect($item->home_release_date?->toDateString())->toBe('1999-08-15')
        ->and(ItemMetadata::fromStored($item->metadata)->homeReleaseDate())->toBe('1999-08-15');
});

test('a movie with only a disc date falls back to it', function () {
    $data = jsonFixture('tmdb/movie');

    // Coyote vs. Acme's shape: TMDB knows the disc release and no digital one.
    foreach ($data['release_dates']['results'] as $i => $region) {
        $data['release_dates']['results'][$i]['release_dates'] = array_values(array_filter(
            $region['release_dates'],
            fn (array $entry): bool => $entry['type'] !== 4,
        ));
    }

    Http::fake(['*/movie/603*' => Http::response($data), ...providerDictionaryFakes()]);

    expect(titleStore()->persist('movie', '603', 'en')->home_release_date?->toDateString())->toBe('1999-11-02');
});

test('a title with no home release leaves the column null', function () {
    Http::fake(providerDictionaryFakes());

    fakeShowTree();

    expect(titleStore()->persist('show', '1399', 'en')->home_release_date)->toBeNull();
});

test('interacting with an episode persists the full show tree', function () {
    Http::fake(providerDictionaryFakes());

    fakeShowTree();

    $item = titleStore()->persist('episode', '1399:2:1', 'en');

    expect($item->type)->toBe('episode')
        ->and($item->provider_ref)->toBe('1399:2:1')
        ->and($item->number)->toBe(1)
        ->and($item->release_date?->toDateString())->toBe('2012-04-01');

    $show = MediaItem::where(['type' => 'show', 'provider_ref' => '1399'])->firstOrFail();

    expect(MediaItem::where('type', 'season')->where('parent_id', $show->id)->count())->toBe(3)
        ->and(MediaItem::where('type', 'episode')->count())->toBe(6);

    $season = MediaItem::where(['type' => 'season', 'provider_ref' => '1399:2'])->firstOrFail();

    expect($item->parent_id)->toBe($season->id)
        ->and($season->parent_id)->toBe($show->id)
        ->and($season->number)->toBe(2);
});

test('an already persisted item short-circuits without provider calls', function () {
    Http::fake(providerDictionaryFakes());

    fakeShowTree();

    titleStore()->persist('season', '1399:1', 'en');

    Http::fake();

    $item = titleStore()->persist('episode', '1399:1:2', 'en');

    expect($item->type)->toBe('episode');
    Http::assertNothingSent();
});

test('a ref the provider does not list throws item not found', function () {
    Http::fake(providerDictionaryFakes());

    fakeShowTree();

    titleStore()->persist('episode', '1399:2:99', 'en');
})->throws(ItemNotFoundException::class);

test('re-running a persist refreshes rows instead of duplicating them', function () {
    Http::fake(providerDictionaryFakes());

    fakeShowTree();

    titleStore()->persist('show', '1399', 'en');
    MediaItem::query()->update(['refreshed_at' => now()->subDay()]);

    titleStore()->persist('season', '1399:1', 'en');

    expect(MediaItem::where('type', 'show')->count())->toBe(1)
        ->and(MediaItem::where('type', 'episode')->count())->toBe(6);
});

test('every level of a persisted tree carries its total runtime', function () {
    Http::fake(providerDictionaryFakes());

    fakeShowTree();

    titleStore()->persist('show', '1399', 'en');

    $show = MediaItem::where(['type' => 'show', 'provider_ref' => '1399'])->firstOrFail();
    $specials = MediaItem::where(['type' => 'season', 'provider_ref' => '1399:0'])->firstOrFail();
    $season = MediaItem::where(['type' => 'season', 'provider_ref' => '1399:1'])->firstOrFail();
    $episode = MediaItem::where(['type' => 'episode', 'provider_ref' => '1399:1:1'])->firstOrFail();

    // Every episode of every season summed — never the typical episode runtime
    // multiplied by the episode count. Specials (45 minutes here) sit outside
    // the show's total but keep one of their own.
    expect($show->total_runtime)->toBe(225)
        ->and($season->total_runtime)->toBe(118)
        ->and($specials->total_runtime)->toBe(45)
        ->and($episode->total_runtime)->toBe($episode->runtime);
});

test('a flat item totals its own runtime', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $movie = titleStore()->persist('movie', '603', 'en');

    expect($movie->total_runtime)->toBe($movie->runtime);
});

test('a refresh reads each level of the tree once, not each of its rows', function () {
    Http::fake(providerDictionaryFakes());
    fakeShowTree();

    $show = titleStore()->persist('show', '1399', 'en');
    $children = MediaItem::query()->whereIn('type', ['season', 'episode'])->count();

    $queries = queriesRunBy(fn () => titleStore()->refresh($show, 'en'));
    $lookups = count(array_filter($queries, fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'select * from "media_items"') && str_contains($query->sql, '"provider_ref" = ?')));

    // Only the root is looked up on its own; three seasons and six episodes are not.
    expect($children)->toBe(9)
        ->and($lookups)->toBe(1);
});
