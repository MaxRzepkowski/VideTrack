<?php

use App\Actions\Media\SyncAirSchedule;
use App\Catalog\ItemMetadata;
use App\Catalog\Providers\Tvmaze\TvmazeClient;
use App\Catalog\TitleStore;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('backfills episode air times only when tvmaze reports a real airtime', function () {
    $show = MediaItem::factory()->show()->create(['metadata' => ['tvdbId' => 12345]]);
    $season = MediaItem::factory()->season($show, 1)->create();
    $e1 = MediaItem::factory()->episode($season, 1, '2026-07-20')->create();
    $e2 = MediaItem::factory()->episode($season, 2, '2026-07-27')->create();
    $e3 = MediaItem::factory()->episode($season, 3, '2026-08-03')->create();

    Http::fake([
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 999, 'name' => 'Test']),
        'api.tvmaze.com/shows/999/episodes' => Http::response([
            ['season' => 1, 'number' => 1, 'airtime' => '21:00', 'airstamp' => '2026-07-20T01:00:00+00:00'],
            // Empty airtime = streaming placeholder (noon UTC) — never stored.
            ['season' => 1, 'number' => 2, 'airtime' => '', 'airstamp' => '2026-07-27T12:00:00+00:00'],
            ['season' => 1, 'number' => 3, 'airtime' => '21:00', 'airstamp' => '2026-08-03T01:00:00+00:00'],
        ]),
    ]);

    app(SyncAirSchedule::class)->execute($show);

    expect($e1->refresh()->release_time?->toIso8601String())->toBe('2026-07-20T01:00:00+00:00')
        ->and($e2->refresh()->release_time)->toBeNull()
        ->and($e3->refresh()->release_time?->toIso8601String())->toBe('2026-08-03T01:00:00+00:00')
        ->and(ItemMetadata::fromStored($show->refresh()->metadata)->module('tvmazeId'))->toBe(999);
});

test('an air time tvmaze gives in UTC is kept on the instance\'s clock', function () {
    instanceTimezone('Europe/Warsaw');
    $show = MediaItem::factory()->show()->create(['metadata' => ['tvdbId' => 12345]]);
    $season = MediaItem::factory()->season($show, 1)->create();
    $episode = MediaItem::factory()->episode($season, 1, '2026-07-20')->create();

    Http::fake([
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 999, 'name' => 'Test']),
        'api.tvmaze.com/shows/999/episodes' => Http::response([
            ['season' => 1, 'number' => 1, 'airtime' => '21:00', 'airstamp' => '2026-07-20T01:00:00+00:00'],
        ]),
    ]);

    app(SyncAirSchedule::class)->execute($show);

    expect(DB::table('media_items')->where('id', $episode->id)->value('release_time'))->toBe('2026-07-20 03:00:00');
});

test('resolves the tvmaze show by name search matched on premiere year', function () {
    $show = MediaItem::factory()->show()->create(['title' => 'Lucky', 'year' => 2026, 'metadata' => []]);
    $season = MediaItem::factory()->season($show, 1)->create();
    $e1 = MediaItem::factory()->episode($season, 1, '2026-07-15')->create();

    Http::fake([
        'api.tvmaze.com/search/shows*' => Http::response([
            ['show' => ['id' => 777, 'premiered' => '2020-01-01']],
            ['show' => ['id' => 555, 'premiered' => '2026-03-01']],
        ]),
        'api.tvmaze.com/shows/555/episodes' => Http::response([
            ['season' => 1, 'number' => 1, 'airtime' => '20:00', 'airstamp' => '2026-07-15T18:00:00+00:00'],
        ]),
    ]);

    app(SyncAirSchedule::class)->execute($show);

    expect(ItemMetadata::fromStored($show->refresh()->metadata)->module('tvmazeId'))->toBe(555)
        ->and($e1->refresh()->release_time?->toIso8601String())->toBe('2026-07-15T18:00:00+00:00');
});

test('a cached tvmaze id skips the lookup on a later run', function () {
    $show = MediaItem::factory()->show()->create(['metadata' => ['modules' => ['tvmazeId' => 42]]]);
    $season = MediaItem::factory()->season($show, 1)->create();
    $e1 = MediaItem::factory()->episode($season, 1, '2026-07-20')->create();

    Http::fake([
        'api.tvmaze.com/shows/42/episodes' => Http::response([
            ['season' => 1, 'number' => 1, 'airtime' => '21:00', 'airstamp' => '2026-07-20T01:00:00+00:00'],
        ]),
    ]);

    app(SyncAirSchedule::class)->execute($show);

    expect($e1->refresh()->release_time?->toIso8601String())->toBe('2026-07-20T01:00:00+00:00');

    // No lookup/search endpoint was faked, so any such call would have failed.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/lookup/')
        || str_contains($request->url(), '/search/'));
});

test('a show tvmaze does not know is remembered, not asked about on every run', function () {
    Http::fake(['api.tvmaze.com/lookup/shows*' => Http::response(null, 404)]);

    $client = app(TvmazeClient::class);

    expect($client->get('/lookup/shows', ['thetvdb' => 404404]))->toBeNull()
        ->and($client->get('/lookup/shows', ['thetvdb' => 404404]))->toBeNull()
        ->and($client->getCached('/lookup/shows', ['thetvdb' => 404404]))->toBeNull();

    Http::assertSentCount(1);
});

test('a refresh of the show keeps the tvmaze id the schedule remembered', function () {
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 82, 'name' => 'Game of Thrones']),
        'api.tvmaze.com/search/shows*' => Http::response([['show' => ['id' => 82, 'premiered' => '2011-04-17']]]),
        'api.tvmaze.com/shows/82/episodes' => Http::response([]),
        ...providerDictionaryFakes(),
    ]);

    $persist = app(TitleStore::class);
    $show = $persist->persist('show', '1399', 'en');

    app(SyncAirSchedule::class)->execute($show);
    expect(ItemMetadata::fromStored($show->refresh()->metadata)->module('tvmazeId'))->toBe(82);

    Cache::flush();
    $persist->refresh($show, 'en');

    expect(ItemMetadata::fromStored($show->refresh()->metadata)->module('tvmazeId'))->toBe(82);
});
