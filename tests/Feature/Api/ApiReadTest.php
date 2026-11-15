<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\SetFavorite;
use App\Library\WatchedAt;
use App\Models\CatalogEvent;
use App\Models\MediaItem;
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
