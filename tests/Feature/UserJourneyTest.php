<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * End-to-end journeys over HTTP: the flows a real user walks
 * through, from registration to the dashboard, with the provider faked.
 */
function fakeJourneyProvider(): void
{
    Http::fake([
        '*/trending/*/week*' => Http::response(['results' => []]),
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
    ]);
}

test('register, watch out of order, and Up Next points at the first gap', function () {
    Http::fake(providerDictionaryFakes());

    fakeJourneyProvider();

    $this->post('/register', [
        'name' => 'Alex',
        'username' => 'alex',
        'email' => 'alex@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    // S01E01, then a jump to S02E01.
    $this->postJson(route('ui.media.plays.store', ['type' => 'episode', 'ref' => '1399:1:1']), ['watched_at' => 'now'])
        ->assertSuccessful();
    $this->postJson(route('ui.media.plays.store', ['type' => 'episode', 'ref' => '1399:2:1']), ['watched_at' => 'now'])
        ->assertSuccessful();

    // Up Next shows the first gap (S01E02), not "last watched + 1".
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('upNext.0.episode.ref', '1399:1:2')
            ->where('upNext.0.watchedEpisodes', 2)
            ->where('upNext.0.airedEpisodes', 4));

    // Closing the gap moves the pointer past the already-watched S02E01.
    $this->postJson(route('ui.media.plays.store', ['type' => 'episode', 'ref' => '1399:1:2']), ['watched_at' => 'now']);

    $this->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('upNext.0.episode.ref', '1399:2:2'));
});

test('plays, ratings and favorites feed the profile and activity pages', function () {
    Http::fake(providerDictionaryFakes());

    fakeJourneyProvider();
    $user = User::factory()->create();

    $this->actingAs($user);
    $this->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => '603']), ['watched_at' => 'now'])
        ->assertSuccessful();
    $this->putJson(route('ui.media.rating.update', ['type' => 'movie', 'ref' => '603']), ['rating' => 9])
        ->assertSuccessful();
    $this->putJson(route('ui.media.favorite.update', ['type' => 'movie', 'ref' => '603']), ['favorite' => true])
        ->assertSuccessful();

    $this->get(route('users.show', ['user' => $user->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('favoritesCount', 1));

    $this->get(route('users.history', ['user' => $user->username]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.media.ref', '603'));
});

test('the watchlist puts an upcoming movie on the calendar', function () {
    Http::fake(['*/movie/603*' => Http::response(array_merge(
        jsonFixture('tmdb/movie'),
        ['release_date' => today()->addDays(3)->toDateString()],
    )), ...providerDictionaryFakes()]);
    $user = User::factory()->create();

    $this->actingAs($user);
    $watchlistId = $user->watchlist()->id;
    $this->putJson(route('ui.media.lists.update', ['type' => 'movie', 'ref' => '603']), ['list_ids' => [$watchlistId]])
        ->assertSuccessful();

    Http::fake(['*/trending/*/week*' => Http::response(['results' => []])]);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('calendar', 1)
            ->where('calendar.0.date', today()->addDays(3)->toDateString())
            ->where('calendar.0.entries.0.media.ref', '603'));
});
