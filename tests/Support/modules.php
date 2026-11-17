<?php

use App\Catalog\Providers\Igdb\IgdbGames;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Optional modules and the side lookups a provider makes on its own.

/**
 * Activates the games module for one test: credentials in config (the
 * registries are container singletons resolved lazily, so setting them
 * before the first resolve is enough) plus a faked Twitch token endpoint.
 * Callers fake the api.igdb.com endpoints they exercise.
 */
function enableGamesModule(): void
{
    config(['tracker.igdb.client_id' => 'testing-id', 'tracker.igdb.client_secret' => 'testing-secret']);

    Http::fake([
        'id.twitch.tv/*' => Http::response(['access_token' => 'testing-token', 'expires_in' => 5000000]),
    ]);
}

/**
 * The answer to a game page's one IGDB multiquery: the game's rows (a
 * fixture), how long it takes, its editions and what it bundles — named as
 * the page asks for them.
 *
 * @param  list<array<string, mixed>>  $game
 * @param  list<array<string, mixed>>  $timeToBeat
 * @param  list<array<string, mixed>>  $editions
 * @param  list<array<string, mixed>>  $bundleContents
 * @return list<array{name: string, result: list<array<string, mixed>>}>
 */
function igdbGamePage(array $game, array $timeToBeat = [], array $editions = [], array $bundleContents = []): array
{
    return [
        ['name' => IgdbGames::GAME, 'result' => $game],
        ['name' => IgdbGames::TIME_TO_BEAT, 'result' => $timeToBeat],
        ['name' => IgdbGames::EDITIONS, 'result' => $editions],
        ['name' => IgdbGames::BUNDLE_CONTENTS, 'result' => $bundleContents],
    ];
}

/**
 * Activates the books module for one test (same lazy-singleton trick as the
 * games module). Every Hardcover call goes to ONE GraphQL endpoint, so the
 * fake dispatches on the operation name in the request body.
 */
function enableBooksModule(): void
{
    config(['tracker.hardcover.api_key' => 'testing-key']);
}

/**
 * Fakes the Hardcover GraphQL endpoint: operation name => fixture name.
 *
 * @param  array<string, string>  $operations
 */
function fakeHardcover(array $operations): void
{
    Http::fake([
        'api.hardcover.app/*' => function ($request) use ($operations) {
            $query = (string) data_get($request->data(), 'query');

            foreach ($operations as $operation => $fixture) {
                if (str_contains($query, "query {$operation}")) {
                    return Http::response(jsonFixture("hardcover/{$fixture}"));
                }
            }

            return Http::response(['data' => []]);
        },
    ]);
}

/**
 * Fakes Hardcover serving a book series: each `Series` query takes the next
 * answer (the last one repeats), and every volume stored is asked for its
 * details, which answer as the `hardcover/book` fixture under that volume's
 * id, title and, when the series names it, release date.
 *
 * @param  array<string, mixed>  ...$answers  `series_by_pk` responses, in the order they are served
 */
function fakeHardcoverSeries(array ...$answers): void
{
    $volumes = [];

    foreach ($answers as $answer) {
        foreach ($answer['data']['series_by_pk']['book_series'] as $member) {
            $volumes[$member['book']['id']] = array_intersect_key($member['book'], array_flip(['title', 'release_date', 'release_year']));
        }
    }

    $asked = 0;

    Http::fake([
        'api.hardcover.app/*' => function (Request $request) use ($answers, $volumes, &$asked) {
            if (str_contains((string) data_get($request->data(), 'query'), 'query Series')) {
                return Http::response($answers[min($asked++, count($answers) - 1)]);
            }

            $id = (int) data_get($request->data(), 'variables.id');
            $book = jsonFixture('hardcover/book');
            $book['data']['books_by_pk'] = ['id' => $id, ...$volumes[$id]] + $book['data']['books_by_pk'];

            return Http::response($book);
        },
    ]);
}

/**
 * Activates the manga module for one test (same lazy-singleton trick as the
 * games module). Callers fake the api.myanimelist.net endpoints they
 * exercise.
 */
function enableMangaModule(): void
{
    config(['tracker.mal.client_id' => 'testing-id']);
}

/**
 * Activates the comics module for one test (same lazy-singleton trick as
 * the games module). Callers fake the comicvine.gamespot.com endpoints they
 * exercise.
 */
function enableComicsModule(): void
{
    config(['tracker.comicvine.api_key' => 'testing-key']);
}

/**
 * Empty answers for the side lookups that rendering or persisting a title
 * makes on its own (TMDB certifications, languages, countries, watch
 * regions; IGDB platforms; the TVmaze airtime lookup). Spread them LAST into a test's own Http::fake([...]) — the
 * first matching stub wins, so a test that fakes a dictionary itself keeps
 * its fixture.
 *
 * @return array<string, mixed>
 */
function providerDictionaryFakes(): array
{
    return [
        'api.themoviedb.org/3/certification/*' => Http::response(['certifications' => []]),
        'api.themoviedb.org/3/configuration/languages*' => Http::response([]),
        'api.themoviedb.org/3/watch/providers/regions*' => Http::response(['results' => []]),
        'api.themoviedb.org/3/watch/providers/movie*' => Http::response(['results' => []]),
        'api.themoviedb.org/3/watch/providers/tv*' => Http::response(['results' => []]),
        'api.themoviedb.org/3/configuration/countries*' => Http::response([]),
        'api.igdb.com/v4/platforms*' => Http::response([]),
        'api.igdb.com/v4/languages*' => Http::response([]),
        'api.igdb.com/v4/game_modes*' => Http::response([]),
        'api.igdb.com/v4/player_perspectives*' => Http::response([]),
        // Persisting a show asks TVmaze for its airtime; unknown there.
        'api.tvmaze.com/*' => Http::response(null, 404),
    ];
}
