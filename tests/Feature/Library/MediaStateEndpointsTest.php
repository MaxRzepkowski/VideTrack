<?php

use App\Catalog\MediaRef;
use App\Enums\PlayOutcome;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot touch media state endpoints', function () {
    $this->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => '603']), ['watched_at' => 'now'])
        ->assertUnauthorized();
});

test('a type this instance does not serve is a resource that does not exist', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('ui.media.plays.store', ['type' => 'game', 'ref' => '1']), ['watched_at' => 'now'])
        ->assertNotFound();
});

test('refs shaped to probe provider URLs or poison the cache are rejected', function () {
    $user = User::factory()->create();

    // Slashes, schemes and whitespace let a crafted ref escape a single path
    // segment; the base request pins the shape provider-agnostically.
    foreach (['../603', 'movie/603', 'http://evil/603', '60 3', ':603', '603&x=1'] as $ref) {
        $this->actingAs($user)
            ->postJson('/ui/v1/media/movie/'.rawurlencode($ref).'/plays', ['watched_at' => 'now'])
            ->assertNotFound();
    }
});

test('opaque provider ref shapes stay valid across providers', function () {
    // Numbers, TMDB season/episode nesting and slug/UUID-style ids all pass —
    // the format rule stays provider-agnostic.
    foreach (['603', '1399:1', '1399:1:2', 'OL27448W', 'a1b2-c3d4'] as $ref) {
        $validator = validator(['ref' => $ref], ['ref' => MediaRef::FORMAT]);

        expect($validator->errors()->has('ref'))->toBeFalse("ref [{$ref}] should pass");
    }
});

test('marking a movie over http persists it and logs a play', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => '603']), ['watched_at' => 'now'])
        ->assertCreated();

    $movie = MediaItem::where(['type' => 'movie', 'provider_ref' => '603'])->firstOrFail();

    expect(Play::where('user_id', $user->id)->where('media_item_id', $movie->id)->count())->toBe(1);
});

test('an explicit date with time is stored to the minute', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => '603']), ['watched_at' => '2026-07-01T21:30']);

    expect(Play::first()->watched_at->toDateTimeString())->toBe('2026-07-01 21:30:00');
});

test('an ISO 8601 instant from the client is stored as the universal time', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => '603']), ['watched_at' => '2026-07-01T19:30:00.000Z'])
        ->assertSuccessful();

    expect(Play::first()->watched_at->toDateTimeString())->toBe('2026-07-01 19:30:00');
});

test('a future explicit date is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => '603']), ['watched_at' => today()->addDay()->toDateString()])
        ->assertJsonValidationErrors('watched_at');
});

test('marking a whole show over http cascades and the page exposes userStates', function () {
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        ...providerDictionaryFakes(),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'show', 'ref' => '1399']), ['watched_at' => 'release'])
        ->assertCreated();

    // Seasons 1 + 2 have 2 aired episodes each; specials skipped.
    expect(Play::count())->toBe(4);

    $this->actingAs($user)
        ->get('/show/1399')
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/ShowDetails')
            ->where('userStates.show:1399.watched', true)
            ->where('userStates.show:1399.plays', 4)
            ->where('userStates.season:1399:0.watched', false)
            ->where('userStates.season:1399:1.watched', true));
});

test('unmarking over http deletes plays through the cascade', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie'))]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => '603']), ['watched_at' => 'now']);
    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => '603']), ['watched_at' => 'now']);

    $this->actingAs($user)
        ->deleteJson(route('ui.media.plays.destroy', ['type' => 'movie', 'ref' => '603']));

    expect(Play::count())->toBe(0);
});

test('rating validates its range and can be removed', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.rating.update', ['type' => 'movie', 'ref' => '603']), ['rating' => 11])
        ->assertJsonValidationErrors('rating');

    $this->actingAs($user)
        ->putJson(route('ui.media.rating.update', ['type' => 'movie', 'ref' => '603']), ['rating' => 8]);

    expect(Rating::first()->rating)->toBe(8);

    $this->actingAs($user)
        ->deleteJson(route('ui.media.rating.destroy', ['type' => 'movie', 'ref' => '603']));

    expect(Rating::count())->toBe(0);
});

test('favorite endpoint sets the desired state', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.favorite.update', ['type' => 'movie', 'ref' => '603']), ['favorite' => true]);

    $this->actingAs($user)
        ->get('/movie/603')
        ->assertInertia(fn (Assert $page) => $page->where('userStates.movie:603.favorite', true));
});

test('dropping is a play entry, open states stay on roots, skips reach seasons', function () {
    Http::fake([
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        ...providerDictionaryFakes(),
    ]);
    $user = User::factory()->create();

    // Dropping a movie closes its (implicit first) attempt — a dated play
    // entry, never a state row.
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => '603']), ['status' => 'dropped']);

    expect(Play::query()->where('outcome', PlayOutcome::Dropped->value)->count())->toBe(1)
        ->and(MediaUserState::query()->count())->toBe(0);

    // Manual in-progress stays exclusive to progress types (games).
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => '603']), ['status' => 'in_progress'])
        ->assertJsonValidationErrors('status');

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'show', 'ref' => '1399']), ['status' => 'on_hold']);

    expect(MediaUserState::query()->count())->toBe(1);

    // Skipping a season is a drop on the season node (settles it for
    // Up Next); open states below the root stay rejected.
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'season', 'ref' => '1399:1']), ['status' => 'dropped']);

    expect(Play::query()->where('outcome', PlayOutcome::Dropped->value)->count())->toBe(2);

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'season', 'ref' => '1399:1']), ['status' => 'on_hold'])
        ->assertJsonValidationErrors('type');

    // Null clears both axes: the show's open state and the movie's drop.
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'show', 'ref' => '1399']), ['status' => null]);

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => '603']), ['status' => null]);

    expect(MediaUserState::query()->count())->toBe(0)
        ->and(Play::query()->where('outcome', PlayOutcome::Dropped->value)->count())->toBe(1);
});

test('a drop can be backdated like a play', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['status' => 'dropped', 'dropped_at' => '2026-01-05'])
        ->assertSuccessful();

    expect(Play::query()->firstOrFail()->watched_at->toDateString())->toBe('2026-01-05');

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['status' => 'dropped', 'dropped_at' => today()->addDay()->toDateString()])
        ->assertJsonValidationErrors('dropped_at');
});

test('list membership sync and quick-create work over the api', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);
    $user = User::factory()->create();

    $watchlist = $user->watchlist();

    $this->actingAs($user)
        ->putJson(route('ui.media.lists.update', ['type' => 'movie', 'ref' => '603']), ['list_ids' => [$watchlist->id]]);

    expect(ListItem::where('list_id', $watchlist->id)->count())->toBe(1);

    // Quick-create from the lists modal: the list, then the membership.
    $custom = MediaList::query()->findOrFail($this->actingAs($user)
        ->postJson(route('ui.lists.store'), ['title' => 'Sci-fi'])
        ->assertCreated()
        ->json('data.id'));

    $this->actingAs($user)
        ->putJson(route('ui.media.lists.update', ['type' => 'movie', 'ref' => '603']), ['list_ids' => [$watchlist->id, $custom->id]])
        ->assertSuccessful();

    expect($custom->system_key)->toBeNull()
        ->and(ListItem::where('list_id', $custom->id)->count())->toBe(1);

    $this->actingAs($user)
        ->get('/movie/603')
        ->assertInertia(fn (Assert $page) => $page
            ->where('userStates.movie:603.listIds', [$watchlist->id, $custom->id])
            ->has('userLists', 2)
            ->where('userLists.0.systemKey', 'watchlist'));
});
