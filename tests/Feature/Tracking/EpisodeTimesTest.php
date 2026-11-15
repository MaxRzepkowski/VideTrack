<?php

use App\Actions\Media\RefreshTitle;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Providers\Tvmaze\TvmazeSchedule;
use App\Catalog\TitleStore;
use App\Jobs\EnrichShowSchedule;
use App\Models\MediaItem;
use App\Models\User;
use App\Tracking\EpisodeTimes;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// Warms the client's episode-list cache the same way the background job does,
// so the render path can then read it cache-only.
function warmTvmazeCache(int $tvmazeId): void
{
    Http::fake([
        "api.tvmaze.com/shows/{$tvmazeId}/episodes" => Http::response([
            ['season' => 1, 'number' => 1, 'airtime' => '21:00', 'airstamp' => '2026-07-20T01:00:00+00:00'],
            // Empty airtime = streaming placeholder — never surfaced.
            ['season' => 1, 'number' => 2, 'airtime' => '', 'airstamp' => '2026-07-27T12:00:00+00:00'],
            ['season' => 2, 'number' => 5, 'airtime' => '21:00', 'airstamp' => '2026-08-03T01:00:00+00:00'],
        ]),
    ]);

    app(TvmazeSchedule::class)->airTimes($tvmazeId);
}

function airingEpisode(int $season, int $number): MediaSummary
{
    return new MediaSummary(type: 'episode', ref: "1399:{$season}:{$number}", provider: 'tmdb', title: 'Episode', number: $number, seasonNumber: $season);
}

test('times of particular episodes come keyed by episode ref, across seasons', function () {
    $show = MediaItem::factory()->show()->create(['provider_ref' => '1399', 'metadata' => ['modules' => ['tvmazeId' => 42]]]);
    warmTvmazeCache(42);

    $times = app(EpisodeTimes::class)->ofEpisodes('1399', [airingEpisode(1, 1), airingEpisode(2, 5)]);

    expect($times)->toBe([
        '1399:1:1' => '2026-07-20T01:00:00+00:00',
        '1399:2:5' => '2026-08-03T01:00:00+00:00',
    ]);
});

test('a season\'s times come keyed by episode number', function () {
    $show = MediaItem::factory()->show()->create(['provider_ref' => '1399', 'metadata' => ['modules' => ['tvmazeId' => 42]]]);
    warmTvmazeCache(42);

    // Season 1: only episode 1 has a real airtime (episode 2 is a placeholder).
    expect(app(EpisodeTimes::class)->ofSeason('1399', 1))
        ->toBe([1 => '2026-07-20T01:00:00+00:00']);
});

test('a cold episode-list cache yields no times, and warming it queues a backfill', function () {
    Bus::fake();
    $show = MediaItem::factory()->show()->create(['provider_ref' => '1399', 'metadata' => ['modules' => ['tvmazeId' => 42]]]);
    $times = app(EpisodeTimes::class);

    // Reading never queues anything; only the explicit warm does.
    expect($times->ofEpisodes('1399', [airingEpisode(1, 1)]))->toBe([]);
    Bus::assertNotDispatched(EnrichShowSchedule::class);

    $times->warmIfCold('1399');

    Bus::assertDispatched(EnrichShowSchedule::class);
});

test('a warm schedule is not warmed again', function () {
    Bus::fake([EnrichShowSchedule::class]);
    MediaItem::factory()->show()->create(['provider_ref' => '1399', 'metadata' => ['modules' => ['tvmazeId' => 42]]]);
    warmTvmazeCache(42);

    app(EpisodeTimes::class)->warmIfCold('1399');

    Bus::assertNotDispatched(EnrichShowSchedule::class);
});

test('a show never matched to tvmaze yields no times and is warmed', function () {
    Bus::fake();
    MediaItem::factory()->show()->create(['provider_ref' => '1399', 'metadata' => []]);

    expect(app(EpisodeTimes::class)->ofEpisodes('1399', [airingEpisode(1, 1)]))->toBe([]);
    app(EpisodeTimes::class)->warmIfCold('1399');

    Bus::assertDispatched(EnrichShowSchedule::class);
});

test('the background warm is throttled to at most once per show', function () {
    Bus::fake();
    $show = MediaItem::factory()->show()->create(['provider_ref' => '1399', 'metadata' => []]);

    $times = app(EpisodeTimes::class);
    $times->warmIfCold('1399');
    $times->warmIfCold('1399');

    Bus::assertDispatchedTimes(EnrichShowSchedule::class, 1);
});

test('an unpersisted show yields no times and queues nothing', function () {
    Bus::fake();

    expect(app(EpisodeTimes::class)->ofEpisodes('does-not-exist', [airingEpisode(1, 1)]))->toBe([]);
    app(EpisodeTimes::class)->warmIfCold('does-not-exist');

    Bus::assertNotDispatched(EnrichShowSchedule::class);
});

test('the season and episode pages get their broadcast times in the follow-up request', function () {
    MediaItem::factory()->show()->create(['provider_ref' => '1399', 'metadata' => ['modules' => ['tvmazeId' => 42]]]);
    warmTvmazeCache(42);
    Http::fake([
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => tmdbShowAnswer(seasons: [1 => jsonFixture('tmdb/season')]),
        ...providerDictionaryFakes(),
    ]);
    $this->actingAs(User::factory()->create());

    // The first render never waits for the schedule.
    $this->get('/show/1399/season/1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('episodeTimes'));

    deferredProp('/show/1399/season/1', 'media/SeasonDetails', 'episodeTimes')
        ->assertOk()
        ->assertJsonPath('props.episodeTimes', [1 => '2026-07-20T01:00:00+00:00']);

    deferredProp('/show/1399/season/1/episode/1', 'media/EpisodeDetails', 'airTime')
        ->assertOk()
        ->assertJsonPath('props.airTime', '2026-07-20T01:00:00+00:00');
});

test('a show new to the library gets its air times in the background, a film does not', function () {
    Bus::fake([EnrichShowSchedule::class]);
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        ...providerDictionaryFakes(),
    ]);

    $persist = app(TitleStore::class);
    $show = $persist->persist('show', '1399', 'en');
    $persist->persist('movie', '603', 'en');
    // Asking again for a stored title is not a first persist.
    $persist->persist('show', '1399', 'en');

    Bus::assertDispatchedTimes(EnrichShowSchedule::class, 1);
    Bus::assertDispatched(EnrichShowSchedule::class, fn (EnrichShowSchedule $job): bool => (fn () => $this->showId)->call($job) === $show->id);
});

test('an episode the stored show lacked brings its air times in again', function () {
    Bus::fake([EnrichShowSchedule::class]);
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        ...providerDictionaryFakes(),
    ]);

    $persist = app(TitleStore::class);
    $persist->persist('show', '1399', 'en');
    // The first pass ran: a pass still waiting would read the new episode itself.
    (new UniqueLock(Cache::driver()))->release(Bus::dispatched(EnrichShowSchedule::class)->firstOrFail());
    MediaItem::query()->where(['type' => 'episode', 'provider_ref' => '1399:1:1'])->delete();

    $episode = $persist->persist('episode', '1399:1:1', 'en');

    expect($episode->provider_ref)->toBe('1399:1:1');
    Bus::assertDispatchedTimes(EnrichShowSchedule::class, 2);
});

/** The stored show, its first pass of air times done, with the bus faked afresh. */
function refreshableShow(): MediaItem
{
    Bus::fake([EnrichShowSchedule::class]);
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        ...providerDictionaryFakes(),
    ]);
    $show = app(TitleStore::class)->persist('show', '1399', 'en');
    (new UniqueLock(Cache::driver()))->release(Bus::dispatched(EnrichShowSchedule::class)->firstOrFail());
    Bus::fake([EnrichShowSchedule::class]);

    return $show;
}

test('a refresh that changed nothing on the schedule leaves the air times alone', function () {
    $show = refreshableShow();
    episodeOf($show, 1, 1)->update(['release_time' => '2011-04-18 01:00:00']);

    app(RefreshTitle::class)->execute($show, 'en');

    Bus::assertNotDispatched(EnrichShowSchedule::class);
});

test('a refresh that brought an episode asks for the air times', function () {
    $show = refreshableShow();
    episodeOf($show, 1, 2)->delete();

    app(RefreshTitle::class)->execute($show, 'en');

    Bus::assertDispatchedTimes(EnrichShowSchedule::class, 1);
});

test('an episode near ahead still without a time is asked about, while the show has times elsewhere', function () {
    // Two days before the second episode: it falls in the window, the first has its slot.
    $this->travelTo(CarbonImmutable::parse('2011-04-22 12:00'));
    $show = refreshableShow();
    episodeOf($show, 1, 1)->update(['release_time' => '2011-04-18 01:00:00']);

    app(RefreshTitle::class)->execute($show, 'en');

    Bus::assertDispatchedTimes(EnrichShowSchedule::class, 1);
});

test('a show the schedule gives no slots is not asked about every day', function () {
    $this->travelTo(CarbonImmutable::parse('2011-04-22 12:00'));
    $show = refreshableShow();

    app(RefreshTitle::class)->execute($show, 'en');

    Bus::assertNotDispatched(EnrichShowSchedule::class);
});
