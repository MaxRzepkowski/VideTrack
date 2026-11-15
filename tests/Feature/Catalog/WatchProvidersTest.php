<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('movie page carries watch providers for the locale-default region', function () {
    Http::fake([
        '*/movie/603/watch/providers*' => Http::response([
            'id' => 603,
            'results' => [
                'US' => [
                    'link' => 'https://www.themoviedb.org/movie/603-the-matrix/watch?locale=US',
                    'flatrate' => [
                        ['provider_name' => 'Netflix', 'logo_path' => '/netflix.jpg', 'display_priority' => 5],
                        ['provider_name' => 'Max', 'logo_path' => '/max.jpg', 'display_priority' => 2],
                    ],
                    'rent' => [
                        ['provider_name' => 'Apple TV', 'logo_path' => '/apple.jpg', 'display_priority' => 1],
                    ],
                ],
            ],
        ]),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/MovieDetails')
            ->where('watchProviders.region', 'US')
            ->where('watchProviders.link', 'https://www.themoviedb.org/movie/603-the-matrix/watch?locale=US')
            // Offers are sorted by TMDB display priority.
            ->where('watchProviders.stream.0.name', 'Max')
            ->where('watchProviders.stream.1.name', 'Netflix')
            ->where('watchProviders.rent.0.logoPath', '/apple.jpg')
            ->where('watchProviders.buy', []));
});

test('the watch region preference overrides the locale default', function () {
    Http::fake([
        '*/movie/603/watch/providers*' => Http::response([
            'id' => 603,
            'results' => [
                'US' => ['flatrate' => [['provider_name' => 'Netflix', 'logo_path' => '/n.jpg']]],
                'PL' => ['buy' => [['provider_name' => 'Player', 'logo_path' => '/player.jpg']]],
            ],
        ]),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        ...providerDictionaryFakes(),
    ]);

    $user = User::factory()->create();
    $user->preferences = ['region' => 'PL'];
    $user->save();

    $this->actingAs($user)
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('watchProviders.region', 'PL')
            ->where('watchProviders.buy.0.name', 'Player')
            ->where('watchProviders.stream', []));
});

test('a region without offers yields no watch providers', function () {
    Http::fake([
        '*/movie/603/watch/providers*' => Http::response([
            'id' => 603,
            'results' => [
                'DE' => ['flatrate' => [['provider_name' => 'Netflix', 'logo_path' => '/n.jpg']]],
            ],
        ]),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('watchProviders', null));
});

test('offer regions span the whole world, not the viewer region', function () {
    Http::fake([
        '*/movie/603/watch/providers*' => Http::response([
            'id' => 603,
            'results' => [
                'US' => ['buy' => [['provider_name' => 'Fandango At Home', 'logo_path' => '/f.jpg']]],
                'DE' => ['flatrate' => [['provider_name' => 'Netflix', 'logo_path' => '/n.jpg']]],
                // An entry with a JustWatch page but nothing to watch on it —
                // TMDB returns these, and counting them would claim a film is
                // available in a country where it is not.
                'PL' => ['link' => 'https://www.themoviedb.org/movie/603/watch?locale=PL'],
            ],
        ]),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        ...providerDictionaryFakes(),
    ]);

    $user = User::factory()->create();
    $user->preferences = ['region' => 'PL'];
    $user->save();

    $this->actingAs($user)
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The viewer's own region has no offer at all…
            ->where('watchProviders', null)
            // …yet the film is plainly watchable elsewhere, which is what tells
            // "no digital date filed" apart from "still cinema-only".
            ->where('offerRegions', ['DE', 'US']));
});

test('show page carries watch providers', function () {
    Http::fake([
        '*/tv/1399/watch/providers*' => Http::response([
            'id' => 1399,
            'results' => [
                'US' => ['flatrate' => [['provider_name' => 'Max', 'logo_path' => '/max.jpg']]],
            ],
        ]),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('show.details', ['ref' => '1399']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/ShowDetails')
            ->where('watchProviders.region', 'US')
            ->where('watchProviders.stream.0.name', 'Max'));
});

test('season page carries its own watch providers without another request', function () {
    $season = jsonFixture('tmdb/season');
    $season['watch/providers'] = [
        'results' => [
            'US' => [
                'link' => 'https://www.themoviedb.org/tv/1399-game-of-thrones/season/1/watch?locale=US',
                'flatrate' => [['provider_name' => 'Season Service', 'logo_path' => '/season.jpg']],
            ],
        ],
    ];

    Http::fake([
        '*/certification/tv/list*' => Http::response(jsonFixture('tmdb/certifications_tv')),
        '*/tv/1399/season/1*' => Http::response($season),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('season.details', ['ref' => '1399', 'number' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/SeasonDetails')
            ->where('watchProviders.region', 'US')
            ->where('watchProviders.stream.0.name', 'Season Service'));

    // Season details + parent show + the independently cached certification
    // dictionary. The availability resolver reads the canonical cache entry
    // primed from the appended season payload.
    Http::assertSentCount(3);
});

test('season page hides watch providers when its own season has no offers', function () {
    $season = jsonFixture('tmdb/season');
    $season['watch/providers'] = ['results' => []];

    Http::fake([
        '*/tv/1399/season/1*' => Http::response($season),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('season.details', ['ref' => '1399', 'number' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('watchProviders', null));
});

test('a season watch providers outage never breaks the season page', function () {
    Http::fake([
        '*/tv/1399/season/1/watch/providers*' => Http::response([], 500),
        // The season fixture carries no appended `watch/providers`, so the
        // resolver asks their own endpoint, which is down.
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399*' => Http::response(jsonFixture('tmdb/tv')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('season.details', ['ref' => '1399', 'number' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('season.title', 'Season 1')
            ->where('watchProviders', null));
});

test('a watch providers outage never breaks the details page', function () {
    Http::fake([
        '*/movie/603/watch/providers*' => Http::response([], 500),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('movie.title', 'The Matrix')
            ->where('watchProviders', null));
});

test('a title free to watch only, with ads or without, still shows where', function () {
    Http::fake([
        '*/movie/603/watch/providers*' => Http::response([
            'id' => 603,
            'results' => [
                'US' => [
                    'link' => 'https://www.themoviedb.org/movie/603-the-matrix/watch?locale=US',
                    'ads' => [
                        ['provider_name' => 'Tubi', 'logo_path' => '/tubi.jpg', 'display_priority' => 3],
                        ['provider_name' => 'Pluto TV', 'logo_path' => '/pluto.jpg', 'display_priority' => 4],
                    ],
                    'free' => [
                        ['provider_name' => 'Pluto TV', 'logo_path' => '/pluto.jpg', 'display_priority' => 4],
                    ],
                ],
            ],
        ]),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        ...providerDictionaryFakes(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('movie.details', ['ref' => '603']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('watchProviders.stream', [])
            // One service offered both ways is one entry.
            ->has('watchProviders.free', 2)
            ->where('watchProviders.free.0.name', 'Tubi')
            ->where('watchProviders.free.1.name', 'Pluto TV'));
});
