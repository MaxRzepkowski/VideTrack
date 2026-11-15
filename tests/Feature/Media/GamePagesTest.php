<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('the game route is 404 when the games module is not configured', function () {
    $this->actingAs(User::factory()->create())
        ->get('/game/7346')
        ->assertNotFound();
});

test('the game details page renders from shared components data', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'), [['id' => 951, 'game_id' => 7346, 'normally' => 332160]])),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/game/7346')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/GameDetails')
            ->where('game.title', 'The Legend of Zelda: Breath of the Wild')
            ->where('game.type', 'game')
            ->where('game.extra.timeToBeat.normally', 5536)
            ->has('game.related', 1)
        );
});

test('a game page carries the banner of its series, counted in games', function () {
    // Five queries, more than the limiter's window lets a frozen clock pass.
    $this->travelBack();
    enableGamesModule();
    $game = jsonFixture('igdb/game');
    $game[0]['collections'] = [['id' => 62, 'name' => 'The Witcher']];
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage($game)),
        '*/collections' => Http::response([['id' => 62, 'name' => 'The Witcher']]),
        '*/collection_memberships' => Http::response([
            ['type' => 1, 'game' => ['id' => 80, 'name' => 'The Witcher', 'game_type' => 0, 'cover' => ['image_id' => 'a']]],
            ['type' => 1, 'game' => ['id' => 478, 'name' => 'The Witcher 2', 'game_type' => 0, 'cover' => ['image_id' => 'b']]],
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('title.details', ['type' => 'game', 'ref' => '7346']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('collection.provider', 'igdb')
            ->where('collection.ref', '62')
            ->where('collection.name', 'The Witcher')
            ->where('collection.partCount', 2)
            ->where('collection.partType', 'game')
        );
});

test('a franchise page shows its games from the provider, and a provider without franchises has none', function () {
    $this->travelBack();
    enableGamesModule();
    Http::fake([
        '*/franchises' => Http::response([['id' => 7, 'name' => 'Resident Evil', 'games' => [1]]]),
        '*/games' => Http::response([['id' => 1, 'name' => 'Resident Evil', 'first_release_date' => 828316800, 'game_type' => 0, 'cover' => ['image_id' => 'a']]]),
        '*/collection_memberships' => Http::response([['game' => 1]]),
    ]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('franchises.show', ['provider' => 'igdb', 'ref' => '7']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/FranchisePage')
            ->where('franchise.name', 'Resident Evil')
            ->where('franchise.series.0.title', 'Resident Evil')
            ->has('franchise.others', 0)
            ->has('userStates.game:1')
        );

    $this->actingAs($viewer)->get(route('franchises.show', ['provider' => 'tmdb', 'ref' => '7']))->assertNotFound();
});

test('a platform page shows the machine and its games, and a provider without platforms has none', function () {
    $this->travelBack();
    enableGamesModule();
    Http::fake([
        '*/platforms' => Http::response([['id' => 167, 'name' => 'PlayStation 5']]),
        '*/games' => Http::response([['id' => 1, 'name' => 'Coming', 'cover' => ['image_id' => 'a']]]),
    ]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('platforms.show', ['provider' => 'igdb', 'ref' => '167']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/PlatformPage')
            ->where('platform.name', 'PlayStation 5')
            ->where('platform.upcoming.0.title', 'Coming')
            ->has('userStates.game:1'));

    $this->actingAs($viewer)->get(route('platforms.show', ['provider' => 'tmdb', 'ref' => '167']))->assertNotFound();
});

test('search targets the first provider by default (no cross-provider merge)', function () {
    enableGamesModule();
    Http::fake([
        '*/search/multi*' => Http::response(jsonFixture('tmdb/search')),
        '*/games' => Http::response(jsonFixture('igdb/search')),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/search?q=matrix')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Search')
            ->where('scope', 'tmdb')
            ->where('results', fn ($results) => collect($results)->pluck('ref')->all() === ['603', '1399', '999'])
        );
});

test('a selected game scope queries only IGDB', function () {
    enableGamesModule();
    Http::fake([
        '*/search/multi*' => Http::response(jsonFixture('tmdb/search')),
        '*/games' => Http::response(jsonFixture('igdb/search')),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/search?q=zelda&scope=igdb')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('results', 2)
            ->where('results.0.type', 'game')
        );

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'search/multi'));
});

test('search still serves TMDB results when IGDB is down', function () {
    enableGamesModule();
    Http::fake([
        '*/search/multi*' => Http::response(jsonFixture('tmdb/search')),
        '*/games' => Http::response([], 500),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/search?q=matrix')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results', fn ($results) => collect($results)->pluck('ref')->all() === ['603', '1399', '999']));
});

test('discover with the game filter builds sections from IGDB capabilities', function () {
    // More requests than the provider allows per second: its rate limiter
    // waits for the window to pass, which a frozen clock never lets happen.
    $this->travelBack();

    enableGamesModule();
    Http::fake([
        '*/popularity_primitives' => Http::response(jsonFixture('igdb/popularity')),
        '*/games/count' => Http::response(['count' => 2]),
        '*/games' => Http::response(jsonFixture('igdb/search')),
        '*/genres' => Http::response([['id' => 12, 'name' => 'Role-playing (RPG)']]),
        // TMDB trending is still consulted for the merged section.
        '*/trending/*/week*' => Http::response(['results' => []]),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/discover?type=game')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/Discover')
            ->where('type', 'game')
            // `person` rides at the end: TMDB can browse people.
            ->where('availableTypes', ['show', 'movie', 'game', 'person'])
            ->has('trending', 2)
            ->has('popular', 2)
            ->has('upcoming', 2)
            ->where('genres', [['ref' => '12', 'name' => 'Role-playing (RPG)']])
        );
});
