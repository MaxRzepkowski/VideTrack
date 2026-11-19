<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Library\Events\PlayRecorded;
use App\Library\Events\RatingChanged;
use App\Models\Favorite;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use App\Notifications\MediaEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * Every write targets a position by type + ref, so the hierarchy is
 * persisted first — which for a movie is one provider call.
 */
function fakeApiMovie(): void
{
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie'))]);
}

function writer(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['read', 'write']);

    return $user;
}

test('logging a play answers with the created count and the new state', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    $user = writer();

    $response = $this->postJson('/api/v1/media/movie/603/plays', ['watched_at' => 'now'])
        ->assertCreated()
        ->assertJsonPath('meta.created', 1)
        ->assertJsonPath('data.state.watched', true)
        ->assertJsonPath('data.state.plays', 1)
        ->assertJsonPath('data.ref', '603')
        ->assertJsonPath('states.movie:603.watched', true);

    expect($response->json('data.state.watchedAt'))->not->toBeNull()
        ->and(Play::where('user_id', $user->id)->count())->toBe(1);
});

test('an episode write answers with the states of the episode, its season and its show', function () {
    Http::fake([
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => tmdbShowAnswer(seasons: [1 => jsonFixture('tmdb/season')]),
        ...providerDictionaryFakes(),
    ]);
    writer();

    $response = $this->postJson('/api/v1/media/episode/1399:1:1/plays', ['watched_at' => 'now'])->assertCreated();

    $states = $response->json('states');

    // The position first, then its ancestors: the store applies them as one picture.
    expect(array_keys($states))->toBe(['episode:1399:1:1', 'season:1399:1', 'show:1399'])
        ->and($states['episode:1399:1:1'])->toBe($response->json('data.state'))
        ->and($states['episode:1399:1:1']['watched'])->toBeTrue()
        ->and($states['show:1399']['watched'])->toBeFalse()
        // The card in its context, as the library tabs list the same episode.
        ->and($response->json('data.extra.seasonNumber'))->toBe(1)
        ->and($response->json('data.extra.showTitle'))->toBe('Game of Thrones');
});

test('a play logged through the api is announced as coming from the api', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    Event::fake([PlayRecorded::class]);
    writer();

    $this->postJson('/api/v1/media/movie/603/plays', ['watched_at' => 'now'])->assertCreated();

    Event::assertDispatched(PlayRecorded::class, fn (PlayRecorded $e): bool => $e->source->value() === 'api');

    // The entry keeps it, and lists it.
    $this->getJson('/api/v1/media/movie/603/plays')->assertJsonPath('data.0.source', 'api');
});

test('the full plays cycle: create, list, delete one, unmark', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    $user = writer();

    $this->postJson('/api/v1/media/movie/603/plays', ['watched_at' => '2026-01-01'])->assertCreated();
    $this->postJson('/api/v1/media/movie/603/plays', ['watched_at' => '2026-07-01'])->assertCreated();

    $plays = $this->getJson('/api/v1/media/movie/603/plays')->assertOk()->json('data');
    expect($plays)->toHaveCount(2);

    $this->deleteJson('/api/v1/plays/'.$plays[0]['id'])
        ->assertOk()
        ->assertJsonPath('meta.deleted', 1)
        ->assertJsonPath('data.state.plays', 1);

    $this->deleteJson('/api/v1/media/movie/603/plays')
        ->assertOk()
        ->assertJsonPath('meta.deleted', 1)
        ->assertJsonPath('data.state.watched', false);

    expect(Play::where('user_id', $user->id)->count())->toBe(0);
});

test('a play amount can be corrected by its own id', function () {
    enableGamesModule();
    $user = writer();
    $game = MediaItem::factory()->game()->create();

    $play = Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $game->id,
        'watched_at' => CarbonImmutable::parse('2026-07-01'),
        'outcome' => PlayOutcome::Completed,
        'amount' => 1200,
    ]);

    $this->patchJson("/api/v1/plays/{$play->id}", ['amount' => 1500])
        ->assertOk()
        ->assertJsonPath('data.amount', 1500)
        ->assertJsonPath("states.game:{$game->provider_ref}.playedAmount", 1500);

    expect($play->refresh()->amount)->toBe(1500);
});

test('somebody else play cannot be touched', function () {
    $play = Play::factory()->create();
    projectEveryLibrary();

    writer();

    $this->deleteJson("/api/v1/plays/{$play->id}")->assertNotFound();
    $this->patchJson("/api/v1/plays/{$play->id}", ['amount' => 5])->assertNotFound();
    // Ownership is checked before the body, so a bad body does not reveal the id exists.
    $this->patchJson("/api/v1/plays/{$play->id}", ['amount' => 0])->assertNotFound();
});

test('rating a title, then clearing it', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    $user = writer();

    $this->putJson('/api/v1/media/movie/603/rating', ['rating' => 9])
        ->assertOk()
        ->assertJsonPath('data.state.rating', 9);

    expect(Rating::where('user_id', $user->id)->value('rating'))->toBe(9);

    $this->deleteJson('/api/v1/media/movie/603/rating')
        ->assertOk()
        ->assertJsonPath('data.state.rating', null);

    expect(Rating::where('user_id', $user->id)->exists())->toBeFalse();
});

test('a rating out of range is a 422 in json', function () {
    fakeApiMovie();
    writer();

    $this->putJson('/api/v1/media/movie/603/rating', ['rating' => 11])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rating')
        ->assertJsonStructure(['message', 'errors']);
});

test('a mutation through the api carries the api as its source', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    Event::fake([RatingChanged::class]);
    writer();

    $this->putJson('/api/v1/media/movie/603/rating', ['rating' => 8])->assertOk();

    Event::assertDispatched(RatingChanged::class, fn (RatingChanged $e): bool => $e->source->value() === 'api');
});

test('a status can be set, and dropping closes the attempt', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    $user = writer();

    $this->putJson('/api/v1/media/movie/603/status', ['status' => 'on_hold'])
        ->assertOk()
        ->assertJsonPath('data.state.status', 'on_hold');

    expect(MediaUserState::where('user_id', $user->id)->value('status'))->toBe(MediaUserStatus::OnHold);

    $this->putJson('/api/v1/media/movie/603/status', ['status' => 'dropped', 'dropped_at' => 'now'])
        ->assertOk();

    expect(Play::where('user_id', $user->id)->where('outcome', PlayOutcome::Dropped->value)->exists())->toBeTrue();
});

test('progress can be logged on a type that counts it', function () {
    enableBooksModule();
    fakeHardcover(['Details' => 'book']);
    $user = writer();

    $this->putJson('/api/v1/media/book/2459845/progress', ['add' => 40])
        ->assertOk()
        ->assertJsonPath('data.state.progress', 40);

    expect(MediaUserState::where('user_id', $user->id)->value('progress'))->toBe(40);
});

test('progress is refused on a type without a counter', function () {
    fakeApiMovie();
    writer();

    $this->putJson('/api/v1/media/movie/603/progress', ['add' => 40])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

test('favorite is idempotent and reversible', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    $user = writer();

    $this->putJson('/api/v1/media/movie/603/favorite', ['favorite' => true])
        ->assertOk()
        ->assertJsonPath('data.state.favorite', true);

    $this->putJson('/api/v1/media/movie/603/favorite', ['favorite' => true])->assertOk();

    expect(Favorite::where('user_id', $user->id)->count())->toBe(1);

    $this->putJson('/api/v1/media/movie/603/favorite', ['favorite' => false])
        ->assertOk()
        ->assertJsonPath('data.state.favorite', false);
});

test('list membership is set as a whole set', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    $user = writer();
    $list = $user->lists()->create(['title' => 'Best of', 'description' => null, 'is_public' => false]);

    $this->putJson('/api/v1/media/movie/603/lists', ['list_ids' => [$list->id]])
        ->assertOk()
        ->assertJsonPath('data.state.listIds', [$list->id]);

    $this->putJson('/api/v1/media/movie/603/lists', ['list_ids' => []])
        ->assertOk()
        ->assertJsonPath('data.state.listIds', []);
});

test('lists can be created, renamed and deleted', function () {
    $user = writer();

    $created = $this->postJson('/api/v1/lists', ['title' => 'Watch with dad'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Watch with dad');

    $id = $created->json('data.id');

    $this->patchJson("/api/v1/lists/{$id}", [
        'title' => 'Watch with mum',
        'description' => 'Sundays',
        'is_public' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Watch with mum')
        ->assertJsonPath('data.isPublic', true);

    $this->deleteJson("/api/v1/lists/{$id}")->assertNoContent();

    expect($user->lists()->whereKey($id)->exists())->toBeFalse();
});

test('a stranger editing a list learns nothing of it, not even that it is a watchlist', function () {
    $stranger = User::factory()->create();

    writer();

    // Without a title the rules would answer 422 for a custom list and pass
    // for the watchlist; the policy answers first.
    $this->patchJson("/api/v1/lists/{$stranger->watchlist()->id}", ['is_public' => true])->assertNotFound();
});

test('somebody else list cannot be edited or deleted', function () {
    $stranger = User::factory()->create();
    $list = $stranger->lists()->create(['title' => 'Theirs', 'description' => null, 'is_public' => true]);

    writer();

    $this->patchJson("/api/v1/lists/{$list->id}", ['title' => 'Mine now', 'description' => null, 'is_public' => true])
        ->assertNotFound();
    $this->deleteJson("/api/v1/lists/{$list->id}")->assertNotFound();
});

test('a list entry can be removed by id', function () {
    $user = writer();
    $movie = MediaItem::factory()->movie()->create();
    $list = $user->lists()->create(['title' => 'Best of', 'description' => null, 'is_public' => false]);
    $entry = $list->items()->create(['media_item_id' => $movie->id, 'position' => 1]);

    $this->deleteJson("/api/v1/lists/{$list->id}/items/{$entry->id}")->assertNoContent();

    expect($list->items()->count())->toBe(0);
});

test('notifications can be marked read', function () {
    $user = writer();
    $user->notify(new MediaEvent(premiereEvent('The Matrix', '603')));

    $id = $user->notifications()->firstOrFail()->id;

    $this->patchJson("/api/v1/notifications/{$id}")->assertNoContent();

    expect($user->notifications()->firstOrFail()->read_at)->not->toBeNull();

    $user->notify(new MediaEvent(premiereEvent('Dune', '604')));

    $this->postJson('/api/v1/notifications/read-all')->assertNoContent();

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('a read-only token is refused every write', function () {
    fakeApiMovie();
    Sanctum::actingAs(User::factory()->create(), ['read']);

    $this->putJson('/api/v1/media/movie/603/rating', ['rating' => 9])->assertForbidden();
    $this->postJson('/api/v1/media/movie/603/plays', ['watched_at' => 'now'])->assertForbidden();
    $this->postJson('/api/v1/lists', ['title' => 'Nope'])->assertForbidden();
});

test('writing to a type this instance does not serve is a 404', function () {
    writer();

    $this->putJson('/api/v1/media/game/1020/rating', ['rating' => 9])->assertNotFound();
});

test('the api and the web endpoint leave the same state behind', function () {
    Http::fake(providerDictionaryFakes());

    fakeApiMovie();
    $viaApi = writer();

    $this->putJson('/api/v1/media/movie/603/rating', ['rating' => 7])->assertOk();

    // The token request above switched the default guard to sanctum; a
    // browser request runs in its own process on the web guard.
    $viaWeb = User::factory()->create();
    $this->actingAs($viaWeb, 'web')
        ->putJson(route('ui.media.rating.update', ['type' => 'movie', 'ref' => '603']), ['rating' => 7])
        ->assertSuccessful();

    expect(Rating::where('user_id', $viaApi->id)->value('rating'))
        ->toBe(Rating::where('user_id', $viaWeb->id)->value('rating'));
});
