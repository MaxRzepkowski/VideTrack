<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\SetFavorite;
use App\Http\Requests\Api\V1\CalendarRequest;
use App\Http\Requests\Api\V1\LookupTitlesRequest;
use App\Library\RatingScale;
use App\Library\WatchedAt;
use App\Models\CatalogEvent;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use App\Notifications\MediaEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

function apiUser(array $abilities = ['read', 'write']): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user, $abilities);

    return $user;
}

test('a movie comes back with its details and the caller state', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $user = apiUser();

    $response = $this->getJson('/api/v1/media/movie/603')
        ->assertOk()
        ->assertJsonPath('data.type', 'movie')
        ->assertJsonPath('data.ref', '603')
        ->assertJsonPath('meta.fromLibrary', false)
        // Typed in the code, where the API contract has it.
        ->assertJsonPath('data.extra.wikidataId', 'Q83495');

    expect($response->json('data.title'))->not->toBeEmpty()
        ->and($response->json('data.state.watched'))->toBeFalse()
        ->and($response->json('data.state.plays'))->toBe(0);
});

test('a ref with colons travels in the path', function () {
    Http::fake([
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    apiUser();

    $this->getJson('/api/v1/media/episode/1399:1:1')
        ->assertOk()
        ->assertJsonPath('data.type', 'episode')
        ->assertJsonPath('data.ref', '1399:1:1');
});

test('a show comes back with its seasons as cards', function () {
    Http::fake(['*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')), ...providerDictionaryFakes()]);

    apiUser();

    $this->getJson('/api/v1/media/show/1399')
        ->assertOk()
        ->assertJsonPath('data.children.0.type', 'season')
        ->assertJsonPath('data.children.0.state', null);
});

test('a type this instance does not serve is a 404', function () {
    apiUser();

    // No IGDB keys in the test config, so the games module is not registered.
    $this->getJson('/api/v1/media/game/1020')->assertNotFound();
    $this->getJson('/api/v1/media/hologram/1')->assertNotFound();
});

test('the plays of a position come back newest first', function () {
    $user = apiUser();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '603']);

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));

    $response = $this->getJson('/api/v1/media/movie/603/plays')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.watchedAt'))->toStartWith('2026-07-01')
        ->and($response->json('data.0.outcome'))->toBe('completed')
        ->and($response->json('data.0.cycle'))->toBe(1);
});

test('a position nobody touched simply has no plays', function () {
    apiUser();

    $this->getJson('/api/v1/media/movie/603/plays')
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('the library pages the history tab', function () {
    $user = apiUser();

    foreach (range(1, 3) as $index) {
        $movie = MediaItem::factory()->movie()->create(['title' => "Film {$index}"]);
        app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse("2026-0{$index}-01")));
    }

    $response = $this->getJson('/api/v1/library?tab=history&per_page=2')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('meta.lastPage', 2);

    expect($response->json('data'))->toHaveCount(2)
        // Newest completion first.
        ->and($response->json('data.0.title'))->toBe('Film 3')
        ->and($response->json('data.0.state.watched'))->toBeTrue();
});

test('the library serves ratings, favorites and open attempts too', function () {
    $user = apiUser();
    $rated = MediaItem::factory()->movie()->create(['title' => 'Rated']);
    $loved = MediaItem::factory()->movie()->create(['title' => 'Loved']);

    app(RateItem::class)->execute($user, $rated, 9);
    app(SetFavorite::class)->execute($user, $loved, true);

    $this->getJson('/api/v1/library?tab=ratings')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Rated');

    $this->getJson('/api/v1/library?tab=favorites')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Loved');

    $this->getJson('/api/v1/library?tab=progress')
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('the library rejects an unknown tab and caps the page size', function () {
    apiUser();

    $this->getJson('/api/v1/library?tab=everything')
        ->assertStatus(422)
        ->assertJsonValidationErrors('tab');

    $this->getJson('/api/v1/library?per_page=5000')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

test('a brand-new account already sees its watchlist', function () {
    apiUser();

    // Without it a client could never learn the id it needs to put a title
    // on the watchlist. It stores no title; the client gets the app's name.
    $this->getJson('/api/v1/lists')
        ->assertOk()
        ->assertJsonPath('data.0.systemKey', 'watchlist')
        ->assertJsonPath('data.0.title', 'Backlog')
        ->assertJsonPath('data.0.itemsCount', 0);
});

test('the lists endpoint serves the user own lists and their items', function () {
    $user = apiUser();
    $movie = MediaItem::factory()->movie()->create(['title' => 'On the list']);
    $list = $user->lists()->create(['title' => 'Best of', 'description' => 'Mine', 'is_public' => false]);
    $list->items()->create(['media_item_id' => $movie->id, 'position' => 1]);

    $index = $this->getJson('/api/v1/lists')->assertOk();

    expect(collect($index->json('data'))->pluck('title'))->toContain('Best of');

    $this->getJson("/api/v1/lists/{$list->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Best of')
        ->assertJsonPath('data.itemsCount', 1)
        ->assertJsonPath('data.items.0.media.title', 'On the list');
});

test('somebody else private list is not readable', function () {
    $stranger = User::factory()->create();
    $list = $stranger->lists()->create(['title' => 'Theirs', 'description' => null, 'is_public' => false]);

    apiUser();

    $this->getJson("/api/v1/lists/{$list->id}")->assertNotFound();
});

test('the calendar serves a window and refuses a silly one', function () {
    $user = apiUser();

    MediaItem::factory()->movie()->create([
        'title' => 'Coming soon',
        'release_date' => CarbonImmutable::today()->addDays(3)->toDateString(),
    ]);

    $from = CarbonImmutable::today()->toDateString();
    $to = CarbonImmutable::today()->addDays(10)->toDateString();

    $this->getJson("/api/v1/calendar?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('meta.from', $from)
        ->assertJsonPath('meta.to', $to);

    $this->getJson("/api/v1/calendar?from={$from}&to=".CarbonImmutable::today()->addYears(3)->toDateString())
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson("/api/v1/calendar?from={$to}&to={$from}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});

test("the calendar's window starts on the caller's own today by default", function () {
    // Noon in Greenwich is two in the morning of the next day on Kiritimati.
    $user = User::factory()->create(['preferences' => ['timezone' => 'Pacific/Kiritimati']]);
    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/calendar')->assertOk()->assertJsonPath('meta.from', '2026-10-11');
});

test('the calendar names each event of a day as the event itself', function () {
    enableGamesModule();
    apiUser();
    CatalogEvent::factory()->create(['name' => 'Showcase', 'starts_at' => CarbonImmutable::parse('2026-10-14 18:00', 'UTC'), 'ends_at' => null]);

    $this->getJson('/api/v1/calendar?from=2026-10-10&to=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-10-14')
        ->assertJsonPath('data.0.events.0.name', 'Showcase')
        ->assertJsonStructure(['data' => [['events' => [['provider', 'ref', 'name', 'startsAt', 'endsAt', 'liveStreamUrl', 'logoPath']]]]]);
});

test('the countdowns run soonest first, years ahead, with every kind counted', function () {
    enableGamesModule();
    $user = apiUser(['read']);

    foreach (['Dune' => '2026-10-12', 'Far Away' => '2030-05-01'] as $title => $date) {
        $movie = MediaItem::factory()->movie()->create(['title' => $title, 'release_date' => $date]);
        $user->watchlist()->items()->create(['media_item_id' => $movie->id, 'position' => 1]);
    }

    CatalogEvent::factory()->create(['name' => 'Showcase', 'starts_at' => CarbonImmutable::parse('2026-10-14 18:00', 'UTC'), 'ends_at' => null]);

    $this->getJson('/api/v1/countdowns')
        ->assertOk()
        ->assertJsonPath('data.0.media.title', 'Dune')
        ->assertJsonPath('data.1.event.name', 'Showcase')
        // Past the calendar's year.
        ->assertJsonPath('data.2.media.title', 'Far Away')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.types', [['type' => 'movie', 'count' => 2], ['type' => 'event', 'count' => 1]]);

    $this->getJson('/api/v1/countdowns?type=movie&q=far')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.media.title', 'Far Away')
        // The kinds still describe the whole set.
        ->assertJsonPath('meta.types.1.type', 'event');

    $this->getJson('/api/v1/countdowns?sort=title&dir=desc&per_page=1&page=2')
        ->assertOk()
        // Showcase, Far Away, Dune.
        ->assertJsonPath('data.0.media.title', 'Far Away')
        ->assertJsonPath('meta.lastPage', 3);
});

test('a kind with nothing counting down answers none, not everything', function () {
    $user = apiUser(['read']);
    $movie = MediaItem::factory()->movie()->create(['release_date' => '2026-10-12']);
    $user->watchlist()->items()->create(['media_item_id' => $movie->id, 'position' => 1]);

    $this->getJson('/api/v1/countdowns?type=show')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/countdowns?type=episode')->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->getJson('/api/v1/countdowns?sort=rating')->assertUnprocessable()->assertJsonValidationErrors('sort');
});

test('statistics count a year, a range or the whole history, a part at a time', function () {
    $user = apiUser(['read']);
    $movie = MediaItem::factory()->movie()->create(['runtime' => 100]);
    $other = MediaItem::factory()->movie()->create(['runtime' => 90]);
    Play::factory()->for($user)->for($movie, 'mediaItem')->create(['watched_at' => '2025-03-01 20:00:00']);
    Play::factory()->for($user)->for($other, 'mediaItem')->create(['watched_at' => '2026-06-01 20:00:00']);
    projectLibrary($user);

    // The caller's current year by default.
    $this->getJson('/api/v1/statistics')
        ->assertOk()
        ->assertJsonPath('meta.period', ['kind' => 'year', 'from' => '2026-01-01', 'to' => '2026-12-31'])
        ->assertJsonPath('data.tiles.movie.minutes', 90)
        ->assertJsonPath('data.years', [2025, 2026]);

    $this->getJson('/api/v1/statistics?period=2025&type=movie')
        ->assertOk()
        ->assertJsonPath('meta.type', 'movie')
        ->assertJsonPath('data.summary.time.total', 100);

    $this->getJson('/api/v1/statistics?period=all')->assertOk()->assertJsonPath('data.titles.completed.total', 2);
    $this->getJson('/api/v1/statistics?from=2025-01-01&to=2025-06-30')->assertOk()->assertJsonPath('data.titles.completed.total', 1);

    $this->getJson('/api/v1/statistics/records?period=2025')
        ->assertOk()
        ->assertJsonStructure(['data' => ['streak', 'busiestDay', 'marathon'], 'meta' => ['period']]);
});

test('statistics refuse a period they cannot read and a section that is not there', function () {
    apiUser(['read']);

    $this->getJson('/api/v1/statistics?period=last-year')->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->getJson('/api/v1/statistics?period=2025&from=2025-01-01&to=2025-02-01')->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->getJson('/api/v1/statistics?from=2025-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson('/api/v1/statistics?from=2025-02-01&to=2025-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson('/api/v1/statistics?type=episode')->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->getJson('/api/v1/statistics/gossip')->assertNotFound();
});

test('discover answers what is trending, popular and coming up, with the caller state', function () {
    Http::fake([
        '*/trending/movie/week*' => Http::response(['results' => [['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/p.jpg', 'release_date' => '1999-03-30']]]),
        '*/trending/tv/week*' => Http::response(['results' => [['id' => 1399, 'name' => 'Game of Thrones', 'poster_path' => '/p.jpg', 'first_air_date' => '2011-04-17']]]),
        '*/movie/popular*' => Http::response(['results' => [['id' => 604, 'title' => 'The Matrix Reloaded', 'poster_path' => '/p.jpg', 'release_date' => '2003-05-15']]]),
        '*/discover/movie*' => Http::response(['results' => [['id' => 605, 'title' => 'The Matrix Revolutions', 'poster_path' => '/p.jpg', 'release_date' => '2026-11-05']]]),
        ...providerDictionaryFakes(),
    ]);
    $user = apiUser(['read']);
    app(SetFavorite::class)->execute($user, MediaItem::factory()->movie()->create(['provider_ref' => '603']), true);

    $this->getJson('/api/v1/discover/movie')
        ->assertOk()
        // Trending keeps the type asked for.
        ->assertJsonCount(1, 'data.trending')
        ->assertJsonPath('data.trending.0.ref', '603')
        ->assertJsonPath('data.trending.0.state.favorite', true)
        ->assertJsonPath('data.popular.0.title', 'The Matrix Reloaded')
        ->assertJsonPath('data.upcoming.0.title', 'The Matrix Revolutions')
        ->assertJsonPath('meta', ['type' => 'movie', 'failed' => false]);
});

test('discover says when the catalogue failed, and knows only the types it can browse', function () {
    Http::fake(['*' => Http::response([], 500)]);
    apiUser(['read']);

    $this->getJson('/api/v1/discover/show')
        ->assertOk()
        ->assertJsonPath('data.trending', [])
        ->assertJsonPath('meta.failed', true);

    // Games are switched off in tests.
    $this->getJson('/api/v1/discover/game')->assertNotFound();
    $this->getJson('/api/v1/discover/episode')->assertNotFound();
});

test('notifications page newest first', function () {
    $user = apiUser();

    $user->notify(new MediaEvent(premiereEvent('The Matrix', '603')));

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.data.key', 'released:movie:603:2026-10-10')
        ->assertJsonPath('data.0.readAt', null)
        ->assertJsonPath('meta.total', 1);
});

test('search asks one scope and reports what the instance offers', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);

    apiUser();

    $response = $this->getJson('/api/v1/search?q=matrix')
        ->assertOk()
        ->assertJsonPath('meta.query', 'matrix')
        ->assertJsonPath('meta.scope', 'tmdb');

    expect($response->json('data'))->not->toBeEmpty()
        ->and($response->json('meta.scopes'))->toContain('tmdb')
        ->and($response->json('data.0.state.watched'))->toBeFalse();
});

test('searching people answers cards without a media state', function () {
    Http::fake(['*/search/person*' => Http::response(['page' => 1, 'total_pages' => 1, 'results' => [
        ['id' => 6384, 'name' => 'Keanu Reeves', 'profile_path' => '/keanu.jpg', 'known_for_department' => 'Acting'],
    ]])]);

    apiUser();

    $this->getJson('/api/v1/search?q=keanu&scope=person')
        ->assertOk()
        ->assertJsonPath('meta.scope', 'person')
        ->assertJsonPath('data.0.title', 'Keanu Reeves')
        ->assertJsonMissingPath('data.0.state');
});

test('search says the provider is unavailable instead of answering nothing found', function () {
    Http::fake(['*/search/company*' => Http::response([], 503)]);
    apiUser();

    $this->getJson('/api/v1/search?q=a24&scope=company')->assertStatus(503);
});

test('search needs something to search for', function () {
    apiUser();

    $this->getJson('/api/v1/search')
        ->assertStatus(422)
        ->assertJsonValidationErrors('q');
});

test('a read-only token cannot be used to write', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read']);

    $this->getJson('/api/v1/me')->assertOk();
});

test('the API rate limit is the configured one', function () {
    config(['tracker.rate_limits.api' => 2]);
    apiUser();

    $this->getJson('/api/v1/me')->assertOk();
    $this->getJson('/api/v1/me')->assertOk();
    $this->getJson('/api/v1/me')->assertStatus(429);
});

test('up next answers the first unwatched episode of every show being watched', function () {
    $user = apiUser(['read']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::now());

    $this->getJson(route('api.v1.up-next'))
        ->assertOk()
        ->assertJsonPath('data.0.show.ref', $show->provider_ref)
        ->assertJsonPath('data.0.episode.number', 2)
        ->assertJsonPath('data.0.airedEpisodes', 2)
        ->assertJsonPath('data.0.remainingEpisodes', 1);
});

test('suggestions list the kinds that have something, and each kind its titles', function () {
    $user = apiUser(['read']);
    $movie = MediaItem::factory()->movie()->create(['title' => 'Out now', 'release_date' => '2026-09-01']);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'created_at' => '2026-03-14 10:00:00']);

    $this->getJson(route('api.v1.suggestions.index'))
        ->assertOk()
        ->assertJsonPath('data.0.key', 'released-since-added')
        ->assertJsonPath('data.0.total', 1);

    $this->getJson(route('api.v1.suggestions.show', ['shelf' => 'released-since-added']))
        ->assertOk()
        ->assertJsonPath('data.0.suggestions.0.media.title', 'Out now')
        ->assertJsonStructure(['data' => [['suggestions' => [['media', 'reason']]]]]);

    $this->getJson(route('api.v1.suggestions.show', ['shelf' => 'no-such-kind']))->assertNotFound();
});

test('many titles are looked up by name at once, each with the caller state', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);
    $user = apiUser(['read']);
    app(RateItem::class)->execute($user, MediaItem::factory()->movie()->create(['provider_ref' => '603']), 8);

    $this->postJson(route('api.v1.lookup'), ['titles' => [
        ['title' => 'The Matrix', 'type' => 'movie', 'year' => 1999],
        ['title' => 'Nothing Like It', 'type' => 'movie'],
    ]])
        ->assertOk()
        ->assertJsonPath('data.0.media.ref', '603')
        ->assertJsonPath('data.0.media.state.rating', 8)
        ->assertJsonPath('data.1.media', null)
        ->assertJsonPath('data.1.unavailable', false);
});

test('a lookup whose catalogue cannot answer says so for its titles', function () {
    Http::fake(['*/search/multi*' => Http::response([], 503)]);
    apiUser(['read']);

    $this->postJson(route('api.v1.lookup'), ['titles' => [['title' => 'The Matrix', 'type' => 'movie']]])
        ->assertOk()
        ->assertJsonPath('data.0.unavailable', true);
});

test('a lookup names a type for every title', function () {
    apiUser(['read']);

    $this->postJson(route('api.v1.lookup'), ['titles' => [['title' => 'The Matrix']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('titles.0.type');
});

test('the calendar takes its window as a count of days', function () {
    apiUser(['read']);

    $this->getJson(route('api.v1.calendar', ['from' => '2026-10-10', 'days' => 7]))
        ->assertOk()
        ->assertJsonPath('meta.to', '2026-10-16');

    $this->getJson(route('api.v1.calendar', ['from' => '2026-10-10', 'days' => 7, 'to' => '2026-10-20']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('days');

    // The widest window counted in days takes its first day too.
    $this->getJson(route('api.v1.calendar', ['from' => '2026-10-10', 'days' => CalendarRequest::MAX_DAYS + 2]))->assertUnprocessable();
    $this->getJson(route('api.v1.calendar', ['from' => '2026-10-10', 'days' => CalendarRequest::MAX_DAYS + 1]))->assertOk();
});

test('a lookup is charged per title against the catalogue budget', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);
    apiUser(['read']);
    $titles = array_fill(0, LookupTitlesRequest::MAX_TITLES, ['title' => 'The Matrix', 'type' => 'movie']);
    $allowed = intdiv(config()->integer('tracker.rate_limits.provider_pages'), LookupTitlesRequest::MAX_TITLES);

    foreach (range(1, $allowed) as $call) {
        $this->postJson(route('api.v1.lookup'), ['titles' => $titles])->assertOk();
    }

    $this->postJson(route('api.v1.lookup'), ['titles' => $titles])->assertTooManyRequests();
});

test('the library keeps only what the user rated at least so high', function () {
    $user = apiUser(['read']);
    app(RateItem::class)->execute($user, MediaItem::factory()->movie()->create(['title' => 'Ten']), RatingScale::MAX);
    app(RateItem::class)->execute($user, MediaItem::factory()->movie()->create(['title' => 'Seven']), 7);

    $this->getJson(route('api.v1.library', ['tab' => 'ratings', 'min_rating' => RatingScale::MAX]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Ten');

    $this->getJson(route('api.v1.library', ['min_rating' => RatingScale::MAX + 1]))->assertUnprocessable();
});
