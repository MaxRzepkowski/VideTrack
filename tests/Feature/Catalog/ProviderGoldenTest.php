<?php

use App\Catalog\MatureContent;
use App\Catalog\ProviderRegistry;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => speakEnglishAndPolish());

/*
 * What each provider makes of its recorded answers, every field of the result
 * compared with the shape recorded in tests/Fixtures/golden. A change to how
 * a payload is read shows here field by field; `GOLDEN_UPDATE=1` records a
 * change that is meant.
 */

/** @return array<string, mixed> */
function tmdbGoldenFakes(): array
{
    return [
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/certification/tv/list*' => Http::response(jsonFixture('tmdb/certifications_tv')),
        '*/search/multi*' => Http::response(jsonFixture('tmdb/search')),
        '*/search/person*' => Http::response(jsonFixture('tmdb/search')),
        '*/trending/*' => Http::response(jsonFixture('tmdb/search')),
        '*/movie/603/changes*' => Http::response(jsonFixture('tmdb/movie_changes')),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*/tv/1399/season/1/episode/1*' => Http::response(jsonFixture('tmdb/episode')),
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        '*/person/popular*' => Http::response(jsonFixture('tmdb/search')),
        '*/person/6384*' => Http::response(jsonFixture('tmdb/person')),
        '*/network/49*' => Http::response(['id' => 49, 'name' => 'HBO', 'logo_path' => '/hbo.png', 'origin_country' => 'US']),
        '*/company/41077*' => Http::response(['id' => 41077, 'name' => 'A24', 'logo_path' => '/a24.png', 'origin_country' => 'US']),
        '*/discover/*' => Http::response(jsonFixture('tmdb/search')),
        ...providerDictionaryFakes(),
    ];
}

test('TMDB reads its recorded answers the same way', function (string $name, Closure $call) {
    Http::fake(tmdbGoldenFakes());

    expectGolden("tmdb-{$name}", $call(tmdbProvider()));
})->with([
    'search' => ['search', fn ($tmdb) => $tmdb->search('matrix', 'en', MatureContent::Suggestive)],
    'movie' => ['movie', fn ($tmdb) => $tmdb->details('movie', '603', 'en')],
    'movie-pl' => ['movie-pl', fn ($tmdb) => $tmdb->details('movie', '603', 'pl', 'PL')],
    'show' => ['show', fn ($tmdb) => $tmdb->details('show', '1399', 'en')],
    'season' => ['season', fn ($tmdb) => $tmdb->details('season', '1399:1', 'en')],
    'specials' => ['specials', fn ($tmdb) => $tmdb->details('season', '1399:0', 'en')],
    'episode' => ['episode', fn ($tmdb) => $tmdb->details('episode', '1399:1:1', 'en')],
    'person' => ['person', fn ($tmdb) => $tmdb->person('6384', 'en', MatureContent::Suggestive)],
    'network' => ['network', fn ($tmdb) => $tmdb->network('49', 'en', MatureContent::Suggestive)],
    'company' => ['company', fn ($tmdb) => $tmdb->company('41077', 'en', MatureContent::Suggestive)],
    'trending' => ['trending', fn ($tmdb) => $tmdb->trending('en')],
    'people' => ['people', fn ($tmdb) => $tmdb->searchPeople('keanu', 'en', MatureContent::Suggestive)],
    'popular-people' => ['popular-people', fn ($tmdb) => $tmdb->popularPeople('en')],
    'by-genre' => ['by-genre', fn ($tmdb) => $tmdb->byGenre('movie', '28', 'en', MatureContent::Suggestive)],
    'leaves' => ['leaves', fn ($tmdb) => $tmdb->leaves('show', '1399', 'en')],
    'ratings' => ['ratings', fn ($tmdb) => $tmdb->episodeRatings('1399', 'en')],
]);

/** @return list<array<string, mixed>> */
function igdbGoldenLudography(): array
{
    return [
        [
            'id' => 7346, 'name' => 'The Legend of Zelda: Breath of the Wild',
            'first_release_date' => 1488499200, 'total_rating' => 95.0, 'game_type' => 0, 'cover' => ['image_id' => 'co3p2d'],
            'involved_companies' => [['company' => ['id' => 70], 'developer' => true, 'publisher' => false]],
        ],
        [
            'id' => 5000, 'name' => 'The Master Trials',
            'first_release_date' => 1498176000, 'total_rating' => 80.0, 'game_type' => 1, 'cover' => ['image_id' => 'codlc1'],
            'involved_companies' => [['company' => 70, 'developer' => false, 'publisher' => true]],
        ],
    ];
}

test('IGDB reads its recorded answers the same way', function (string $name, Closure $call) {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbGamePage(jsonFixture('igdb/game'), [['id' => 951, 'game_id' => 7346, 'normally' => 332160, 'hastily' => 200000, 'completely' => 500000]])),
        '*/companies' => Http::response(jsonFixture('igdb/company')),
        '*/popularity_primitives' => Http::response(jsonFixture('igdb/popularity')),
        '*/games/count' => Http::response(['count' => 42]),
        '*/genres' => Http::response([['id' => 31, 'name' => 'Adventure'], ['id' => 'x'], ['name' => 'Nameless']]),
        '*/platforms' => Http::response([['id' => 130, 'name' => 'Nintendo Switch'], ['id' => 6, 'name' => 'PC (Microsoft Windows)']]),
        '*/games' => function ($request) {
            $body = $request->body();

            return match (true) {
                // A company's ludography asks by the company.
                str_contains($body, 'involved_companies.company') => Http::response(igdbGoldenLudography()),
                str_contains($body, 'search '), str_contains($body, 'where id = (115289') => Http::response(jsonFixture('igdb/search')),
                default => Http::response(jsonFixture('igdb/game')),
            };
        },
    ]);

    expectGolden("igdb-{$name}", $call(app(ProviderRegistry::class)->get('igdb')));
})->with([
    'search' => ['search', fn ($igdb) => $igdb->search('hollow knight', 'en', MatureContent::Suggestive)],
    'game' => ['game', fn ($igdb) => $igdb->details('game', '7346', 'en', 'PL')],
    'company' => ['company', fn ($igdb) => $igdb->company('70', 'en', MatureContent::Suggestive)],
    'trending' => ['trending', fn ($igdb) => $igdb->trending('en')],
    'genres' => ['genres', fn ($igdb) => $igdb->genres('game', 'en')],
    'by-genre' => ['by-genre', fn ($igdb) => $igdb->byGenre('game', '31', 'en', MatureContent::Suggestive)],
]);

test('MAL reads its recorded answers the same way', function (string $name, Closure $call) {
    enableMangaModule();
    Http::fake([
        'api.myanimelist.net/v2/manga/ranking*' => Http::response(jsonFixture('mal/ranking')),
        'api.myanimelist.net/v2/manga/2*' => Http::response(jsonFixture('mal/details')),
        'api.myanimelist.net/v2/manga?*' => Http::response(jsonFixture('mal/search')),
    ]);

    expectGolden("mal-{$name}", $call(app(ProviderRegistry::class)->get('mal')));
})->with([
    'search' => ['search', fn ($mal) => $mal->search('berserk', 'en', MatureContent::Suggestive)],
    'manga' => ['manga', fn ($mal) => $mal->details('manga', '2', 'en')],
    'trending' => ['trending', fn ($mal) => $mal->trending('en')],
]);

test('ComicVine reads its recorded answers the same way', function (string $name, Closure $call) {
    enableComicsModule();
    Http::fake([
        'comicvine.gamespot.com/api/search/*' => Http::response(jsonFixture('comicvine/search')),
        'comicvine.gamespot.com/api/volume/4050-4207/*' => Http::response(jsonFixture('comicvine/volume')),
    ]);

    expectGolden("comicvine-{$name}", $call(app(ProviderRegistry::class)->get('comicvine')));
})->with([
    'search' => ['search', fn ($comicVine) => $comicVine->search('sandman', 'en', MatureContent::Suggestive)],
    'volume' => ['volume', fn ($comicVine) => $comicVine->details('comic', '4207', 'en')],
]);

test('Hardcover reads its recorded answers the same way', function (string $name, Closure $call) {
    enableBooksModule();
    fakeHardcover([
        'Search' => 'search',
        'Details' => 'book',
        'Author' => 'author',
        'BooksByIds' => 'books',
        'Trending' => 'trending',
        'Genres' => 'genres',
    ]);

    expectGolden("hardcover-{$name}", $call(app(ProviderRegistry::class)->get('hardcover')));
})->with([
    'search' => ['search', fn ($hardcover) => $hardcover->search('hobbit', 'en', MatureContent::Suggestive)],
    'book' => ['book', fn ($hardcover) => $hardcover->details('book', '2459845', 'en')],
    'author' => ['author', fn ($hardcover) => $hardcover->person('132049', 'en', MatureContent::Suggestive)],
    'trending' => ['trending', fn ($hardcover) => $hardcover->trending('en')],
    'genres' => ['genres', fn ($hardcover) => $hardcover->genres('book', 'en')],
]);
