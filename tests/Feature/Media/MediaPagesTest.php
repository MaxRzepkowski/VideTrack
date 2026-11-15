<?php

use App\Models\MediaItem;
use App\Models\Play;
use App\Models\Rating;
use App\Models\RecentView;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login'));
})->with(['/search', '/discover', '/movie/603', '/show/1399', '/show/1399/season/1', '/show/1399/season/1/episode/1', '/person/tmdb/6384']);

test('discover page renders sections for a type filter', function () {
    Http::fake([
        '*/trending/movie/week*' => Http::response(['results' => [
            ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m.jpg', 'release_date' => '1999-03-30'],
        ]]),
        '*/trending/tv/week*' => Http::response(['results' => [
            ['id' => 1399, 'name' => 'Game of Thrones', 'poster_path' => '/g.jpg', 'first_air_date' => '2011-04-17'],
        ]]),
        '*/tv/popular*' => Http::response(['results' => [
            ['id' => 100, 'name' => 'Popular Show', 'poster_path' => '/p.jpg', 'first_air_date' => '2020-01-01', 'vote_average' => 8.1],
        ]]),
        '*/tv/on_the_air*' => Http::response(['results' => [
            ['id' => 101, 'name' => 'Airing Show', 'poster_path' => '/a.jpg', 'first_air_date' => '2026-06-01'],
        ]]),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Discover')
            ->where('type', 'show')
            ->has('trending', 1)
            ->where('trending.0.title', 'Game of Thrones')
            ->has('popular', 1)
            ->where('popular.0.title', 'Popular Show')
            ->has('upcoming', 1)
            ->where('upcoming.0.title', 'Airing Show')
            ->where('genres.0', ['ref' => '18', 'name' => 'Drama'])
            ->where('genre', null)
            ->where('failed', false));
});

test('discover defaults to shows and only queries the show provider', function () {
    Http::fake([
        '*/trending/movie/week*' => Http::response(['results' => [
            ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m.jpg', 'release_date' => '1999-03-30'],
        ]]),
        '*/trending/tv/week*' => Http::response(['results' => [
            ['id' => 1399, 'name' => 'Game of Thrones', 'poster_path' => '/g.jpg', 'first_air_date' => '2011-04-17'],
        ]]),
        '*/tv/popular*' => Http::response(['results' => [
            ['id' => 100, 'name' => 'Popular Show', 'poster_path' => '/p.jpg', 'first_air_date' => '2020-01-01'],
        ]]),
        '*/tv/on_the_air*' => Http::response(['results' => []]),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // No type param defaults to shows; trending keeps shows only.
            ->where('type', 'show')
            ->where('trending.0.title', 'Game of Thrones')
            ->where('popular.0.title', 'Popular Show')
            ->has('genres', 1));

    // Browsing shows must not touch the movie discover endpoints.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/movie/popular')
        || str_contains($request->url(), '/movie/upcoming'));
});

test('movie upcoming is a discover query floored at the primary release date, not the leaky legacy endpoint', function () {
    Http::fake([
        '*/trending/*/week*' => Http::response(['results' => []]),
        '*/movie/popular*' => Http::response(['results' => []]),
        '*/discover/movie*' => Http::response(['results' => [
            ['id' => 200, 'title' => 'Future Film', 'poster_path' => '/f.jpg', 'release_date' => '2027-01-01'],
        ]]),
        '*/genre/movie/list*' => Http::response(['genres' => [['id' => 28, 'name' => 'Action']]]),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'movie']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('type', 'movie')
            ->where('upcoming.0.title', 'Future Film'));

    // The legacy movie/upcoming endpoint leaks already-released films; we must
    // never call it. The floor is on primary_release_date, not release_date, or
    // classics with future re-release dates (Star Wars, Endgame) would surface.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/movie/upcoming'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/discover/movie')
        && str_contains(urldecode($request->url()), 'primary_release_date.gte='));
});

// A provider with the filtered contract answers `genre` with a
// redirect to the canonical filtered URL — the chips all over the app keep
// working, and there is only ever one address for one selection. The
// by-genre listing serves providers without that contract.
test('a by-name genre ref redirects to the canonical filtered url', function () {
    Http::fake([
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show', 'genre' => '18']))
        ->assertRedirect(route('discover', ['type' => 'show', 'f' => ['genres' => ['18']]]));
});

test('a by-name genre name resolves to its ref and keeps the page', function () {
    Http::fake([
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        '*/discover/tv*' => Http::response([
            'page' => 2,
            'total_pages' => 7,
            'total_results' => 132,
            'results' => [
                ['id' => 300, 'name' => 'Drama Show', 'poster_path' => '/d.jpg', 'first_air_date' => '2019-01-01'],
            ],
        ]),
        ...providerDictionaryFakes(),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'show', 'genre' => 'Drama', 'page' => 2]))
        ->assertRedirect(route('discover', ['type' => 'show', 'f' => ['genres' => ['18']], 'page' => 2]));

    $this->actingAs($user)
        ->get(route('discover', ['type' => 'show', 'page' => 2, 'f' => ['genres' => ['18']]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeFilters.genres', ['18'])
            ->where('filteredItems.0.title', 'Drama Show')
            ->where('filteredPagination.currentPage', 2)
            ->where('filteredPagination.totalPages', 7)
            // A filtered grid replaces the browse sections.
            ->where('trending', [])
            ->where('popular', []));
});

test('unknown discover genres return 404, and a typo in a link is not an error to report', function () {
    Http::fake(['*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]), ...providerDictionaryFakes()]);
    Exceptions::fake();

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show', 'genre' => 'Nope']))
        ->assertNotFound();

    Exceptions::assertNothingReported();
});

test('a provider outage flags the discover page instead of breaking it', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'show']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('failed', true)
            ->where('trending', [])
            ->where('popular', []));
});

test('discover rejects unknown type filters', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'game']))
        ->assertNotFound();
});

test('search page renders without a query and never calls the provider', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->get(route('search'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Search')
            ->where('query', '')
            ->where('results', null));

    Http::assertNothingSent();
});

test('a pasted wall of text is searched cut to size, not refused', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);

    $this->actingAs(User::factory()->create())
        ->get(route('search', ['q' => str_repeat('a', 250)]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('query', str_repeat('a', 200)));
});

test('search page returns mapped results for a query', function () {
    Http::fake(['*/search/multi*' => Http::response(jsonFixture('tmdb/search'))]);

    $this->actingAs(User::factory()->create())
        ->get(route('search', ['q' => 'matrix']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Search')
            ->where('query', 'matrix')
            ->where('results', fn ($results) => collect($results)->pluck('ref')->all() === ['603', '1399', '999'])
            ->where('results.0.title', 'The Matrix')
            ->where('results.2.posterPath', null));
});

test('the people scope searches TMDB people and drops coverless hits', function () {
    Http::fake(['*/search/person*' => Http::response([
        'results' => [
            [
                'id' => 6384,
                'name' => 'Keanu Reeves',
                'profile_path' => '/keanu.jpg',
                'known_for_department' => 'Acting',
                'known_for' => [['title' => 'The Matrix'], ['name' => 'John Wick']],
            ],
            ['id' => 999, 'name' => 'No Portrait', 'profile_path' => null],
        ],
    ])]);

    $this->actingAs(User::factory()->create())
        ->get(route('search', ['q' => 'keanu', 'scope' => 'person']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Search')
            ->where('scope', 'person')
            ->has('results', 1)
            ->where('results.0.type', 'person')
            ->where('results.0.ref', '6384')
            ->where('results.0.title', 'Keanu Reeves')
            ->where('results.0.extra.knownForDepartment', 'acting')
            ->where('results.0.extra.knownFor', ['The Matrix', 'John Wick'])
            ->where('userStates', [])
        );
});

test('movie details page renders', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/MovieDetails')
            ->where('movie.title', 'The Matrix')
            ->where('movie.extra.budget', 63000000));
});

test('show details page renders with seasons', function () {
    Http::fake(['*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')), ...providerDictionaryFakes()]);

    $this->actingAs(User::factory()->create())
        ->get(route('show.details', ['ref' => '1399']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/ShowDetails')
            ->where('show.title', 'Game of Thrones')
            ->has('show.children', 3));
});

test('season details page renders with episodes', function () {
    Http::fake([
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('season.details', ['ref' => '1399', 'number' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/SeasonDetails')
            ->where('season.extra.showTitle', 'Game of Thrones')
            ->has('season.children', 2));
});

test('episode details page renders with the episode and its neighbours', function () {
    Http::fake([
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('episode.details', ['ref' => '1399', 'number' => 1, 'episode' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/EpisodeDetails')
            ->where('episode.title', 'Winter Is Coming')
            ->where('episode.extra.showTitle', 'Game of Thrones')
            ->where('episode.extra.next.ref', '1399:1:2')
            ->has('episode.cast', 3));
});

test('a detail page records the view and exposes recently viewed items', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $user = User::factory()->create();
    RecentView::query()->create([
        'user_id' => $user->id,
        'type' => 'show',
        'provider_ref' => '1399',
        'title' => 'Game of Thrones',
        'poster_path' => '/got.jpg',
        'viewed_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/MovieDetails')
            // The current movie is excluded; the earlier show remains.
            ->has('recentlyViewed', 1)
            ->where('recentlyViewed.0.ref', '1399'));

    expect(RecentView::query()->where('user_id', $user->id)->where('provider_ref', '603')->exists())->toBeTrue();
});

test('person details page renders', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $this->actingAs(User::factory()->create())
        ->get(route('person.details', ['provider' => 'tmdb', 'ref' => '6384']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/CreatorPage')->where('kind', 'person')
            ->where('creator.name', 'Keanu Reeves'));
});

test('person details carry user states for the credit cards', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '603']);

    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'rating' => 8]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'watched_at' => now()]);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route('person.details', ['provider' => 'tmdb', 'ref' => '6384']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/CreatorPage')->where('kind', 'person')
            ->where('userStates.movie:603.rating', 8)
            ->where('userStates.movie:603.watched', true));
});

test('company details page renders', function () {
    enableGamesModule();
    Http::fake([
        '*/companies' => Http::response(jsonFixture('igdb/company')),
        '*/games' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('company.details', ['provider' => 'igdb', 'ref' => '70']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/CreatorPage')->where('kind', 'company')
            ->where('creator.name', 'Nintendo'));
});

test('a creator page for an unknown provider is 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/person/nope/6384')
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get('/company/nope/70')
        ->assertNotFound();
});

test('unknown movie returns 404', function () {
    Http::fake(['*/movie/999999*' => Http::response([], 404)]);

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '999999']))
        ->assertNotFound();
});

test('provider outage returns 503', function () {
    Http::fake(['*/movie/603*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '603']))
        ->assertServiceUnavailable();
});

test('non-numeric refs are rejected by routing', function () {
    $this->actingAs(User::factory()->create())
        ->get('/movie/not-a-number')
        ->assertNotFound();
});

test('season episodes expose the curated finale marker', function () {
    Http::fake([
        '*/tv/1399/season/1*' => Http::response([...jsonFixture('tmdb/season'), 'episodes' => [
            ['episode_number' => 1, 'name' => 'Regular', 'air_date' => '2011-04-17', 'episode_type' => 'standard'],
            ['episode_number' => 2, 'name' => 'Break', 'air_date' => '2011-04-24', 'episode_type' => 'mid_season'],
        ]]),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('season.details', ['ref' => '1399', 'number' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('season.children.0.extra.episodeType', 'standard')
            ->where('season.children.0.extra.episodeBadge', 'series_premiere')
            ->where('season.children.1.extra.episodeType', 'mid_season')
            ->where('season.children.1.extra.episodeBadge', 'mid_season_finale'));
});

test('a finale of the last season of an ended show is marked as the series finale', function () {
    Http::fake([
        '*/tv/1399/season/2*' => Http::response([...jsonFixture('tmdb/season2'), 'episodes' => [
            ['episode_number' => 1, 'name' => 'Opener', 'air_date' => '2012-04-01', 'episode_type' => 'standard'],
            ['episode_number' => 2, 'name' => 'The End', 'air_date' => '2012-06-03', 'episode_type' => 'finale'],
        ]]),
        // The fixture show is Ended with 2 seasons — season 2 is the last.
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('season.details', ['ref' => '1399', 'number' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('season.children.1.extra.episodeType', 'finale')
            ->where('season.children.1.extra.episodeBadge', 'series_finale')
            ->where('season.children.0.extra.episodeBadge', 'season_premiere'));
});
