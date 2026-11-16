<?php

use App\Catalog\Providers\Tmdb\TmdbProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function tmdbProvider(): TmdbProvider
{
    return app(TmdbProvider::class);
}

/**
 * A Polish film as TMDB serves it: the Polish row filed, the English one
 * translated, posters in both languages and one without, the top logo Polish.
 *
 * @return array<string, mixed>
 */
function tmdbPolishFilm(): array
{
    $data = jsonFixture('tmdb/movie');
    $data['id'] = 31407;
    $data['title'] = 'Miś';
    $data['original_title'] = 'Miś';
    $data['original_language'] = 'pl';
    $data['poster_path'] = '/pl-poster.jpg';
    $data['translations'] = ['translations' => [
        ['iso_639_1' => 'en', 'iso_3166_1' => 'US', 'data' => ['title' => 'Teddy Bear']],
        ['iso_639_1' => 'pl', 'iso_3166_1' => 'PL', 'data' => ['title' => 'Miś']],
    ]];
    $data['images'] = [
        'posters' => [
            ['iso_639_1' => 'pl', 'file_path' => '/pl-poster.jpg'],
            ['iso_639_1' => 'pl', 'file_path' => '/pl-poster-2.jpg'],
            ['iso_639_1' => 'en', 'file_path' => '/en-poster.jpg'],
            ['iso_639_1' => null, 'file_path' => '/textless.jpg'],
        ],
        'backdrops' => [['iso_639_1' => null, 'file_path' => '/backdrop.jpg']],
        'logos' => [
            ['iso_639_1' => 'pl', 'file_path' => '/pl-logo.png'],
            ['iso_639_1' => 'en', 'file_path' => '/en-logo.png'],
        ],
    ];

    return $data;
}

/**
 * The seasons of the Game of Thrones fixture by number, as their own
 * endpoints serve them.
 *
 * @return array<int, array<string, mixed>>
 */
function tmdbShowSeasons(): array
{
    return [0 => jsonFixture('tmdb/season0'), 1 => jsonFixture('tmdb/season'), 2 => jsonFixture('tmdb/season2')];
}

/**
 * TMDB's answer for a show (the Game of Thrones fixture by default) that also
 * appends each season it is asked for (`append_to_response=season/N`), bare,
 * the way a show's whole tree is fetched in one request.
 *
 * @param  array<string, mixed>|null  $show
 * @param  array<int, array<string, mixed>>|null  $seasons  by number; {@see tmdbShowSeasons()} by default
 */
function tmdbShowAnswer(?array $show = null, ?array $seasons = null): Closure
{
    $show ??= jsonFixture('tmdb/tv');
    $seasons ??= tmdbShowSeasons();

    return function (Request $request) use ($show, $seasons) {
        $body = $show;
        $appended = $request->data()['append_to_response'] ?? '';

        foreach (explode(',', is_string($appended) ? $appended : '') as $part) {
            if (preg_match('#^season/(\d+)$#', $part, $match) === 1 && isset($seasons[(int) $match[1]])) {
                // An appended season comes without its own appends.
                $body[$part] = array_diff_key($seasons[(int) $match[1]], array_flip(['videos', 'aggregate_credits', 'watch/providers']));
            }
        }

        return Http::response($body);
    };
}

/**
 * TMDB's search knowing titles by the exact query: each answers with its one
 * result, any other query with none.
 *
 * @param  array<string, array<string, mixed>>  $results  query => one `search/multi` result
 */
function tmdbSearchAnswer(array $results): Closure
{
    return function (Request $request) use ($results) {
        $result = $results[$request->data()['query'] ?? ''] ?? null;

        return Http::response(['page' => 1, 'results' => $result === null ? [] : [$result], 'total_pages' => 1, 'total_results' => $result === null ? 0 : 1]);
    };
}
