<?php

use App\Catalog\ProviderCacheContext;
use App\Catalog\Providers\Tmdb\TmdbPayload;
use App\Catalog\TitleStore;
use App\Models\MediaItem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => speakEnglishAndPolish());

/*
 * What a stored show tree holds, field by field, whichever way the provider
 * is asked for it: the fake answers a season both on its own endpoint and
 * appended to the show (`append_to_response=season/N`), so the pattern below
 * pins the tree, not the requests that built it.
 */

/**
 * Game of Thrones as an ended three-season show: specials, a season TMDB
 * leaves undated, a mid-season finale, a season finale, the series finale
 * and an episode without an air date.
 *
 * @return array{show: array<string, mixed>, seasons: array<int, array<string, mixed>>}
 */
function showTreeFixture(): array
{
    $show = jsonFixture('tmdb/tv');
    $show['number_of_seasons'] = 3;
    $show['seasons'][] = [
        'id' => 3629, 'season_number' => 3, 'name' => 'Season 3', 'episode_count' => 3,
        'air_date' => null, 'poster_path' => '/got-s3.jpg', 'vote_average' => 8.6,
    ];

    $seasons = [
        0 => jsonFixture('tmdb/season0'),
        1 => jsonFixture('tmdb/season'),
        2 => jsonFixture('tmdb/season2'),
    ];
    $seasons[1]['episodes'][1]['episode_type'] = 'mid_season';
    $seasons[2]['episodes'][1]['episode_type'] = 'finale';
    $seasons[3] = [
        'id' => 3629, 'name' => 'Season 3', 'overview' => 'The war reaches the Twins.', 'air_date' => null,
        'season_number' => 3, 'poster_path' => '/got-s3.jpg', 'vote_average' => 8.6,
        'episodes' => [
            ['episode_number' => 1, 'name' => 'Valar Dohaeris', 'air_date' => '2013-03-31', 'runtime' => 55,
                'overview' => 'Jon meets the King-Beyond-the-Wall.', 'still_path' => '/got-s3e1.jpg', 'vote_average' => 8.2, 'episode_type' => 'standard'],
            ['episode_number' => 2, 'name' => 'Dark Wings, Dark Words', 'air_date' => '2013-04-07', 'runtime' => 57,
                'overview' => 'Sansa says too much.', 'still_path' => '/got-s3e2.jpg', 'vote_average' => 8.0, 'episode_type' => 'finale'],
            ['episode_number' => 3, 'name' => 'Untitled', 'air_date' => null, 'runtime' => null,
                'overview' => '', 'still_path' => null, 'vote_average' => 0],
        ],
    ];

    return ['show' => $show, 'seasons' => $seasons];
}

/**
 * Serves the show and its seasons in both shapes TMDB knows, counting every
 * request under `tv/1399`.
 *
 * @param  array{show: array<string, mixed>, seasons: array<int, array<string, mixed>>}  $tree
 */
function fakeShowTreeEndpoints(array $tree, int &$requests): void
{
    Http::fake([
        ...providerDictionaryFakes(),
        'api.themoviedb.org/3/tv/1399*' => function (Request $request) use ($tree, &$requests) {
            $requests++;
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (preg_match('#/tv/1399/season/(\d+)$#', $path, $match) === 1) {
                $season = $tree['seasons'][(int) $match[1]] ?? null;

                return $season === null ? Http::response(['status_code' => 34], 404) : Http::response($season);
            }

            $body = $tree['show'];

            foreach (explode(',', (string) ($request->data()['append_to_response'] ?? '')) as $part) {
                if (preg_match('#^season/(\d+)$#', $part, $match) === 1 && isset($tree['seasons'][(int) $match[1]])) {
                    // An appended season is the bare season, without its own appends.
                    $body[$part] = array_diff_key($tree['seasons'][(int) $match[1]], array_flip(['videos', 'aggregate_credits', 'watch/providers']));
                }
            }

            return Http::response($body);
        },
    ]);
}

/**
 * Every stored row of the tree, without ids and clocks; the parent is named
 * by its ref.
 *
 * @return array<string, array<string, mixed>>
 */
function storedShowTree(): array
{
    $refs = MediaItem::query()->pluck('provider_ref', 'id');

    return MediaItem::query()->orderBy('type')->orderBy('provider_ref')->get()
        ->mapWithKeys(function (MediaItem $item) use ($refs): array {
            $row = array_diff_key($item->getAttributes(), array_flip(['id', 'parent_id', 'created_at', 'updated_at', 'refreshed_at']));
            $row['parent'] = $item->parent_id === null ? null : $refs[$item->parent_id];
            $row['metadata'] = json_decode((string) $row['metadata'], true);
            ksort($row);

            return ["{$item->type}:{$item->provider_ref}" => $row];
        })
        ->all();
}

test('a stored show tree holds every field the provider gave it', function () {
    $requests = 0;
    fakeShowTreeEndpoints(showTreeFixture(), $requests);

    app(TitleStore::class)->persist('show', '1399', 'en');

    expect(storedShowTree())->toBe(jsonFixture('tmdb/show-tree'));
});

test('a refresh rewrites the stored tree to the same fields', function () {
    $requests = 0;
    fakeShowTreeEndpoints(showTreeFixture(), $requests);

    $show = app(TitleStore::class)->persist('show', '1399', 'en');
    app(TitleStore::class)->refresh($show, 'en');

    expect(storedShowTree())->toBe(jsonFixture('tmdb/show-tree'));
});

/**
 * A dated show of $count seasons, two episodes each, no specials.
 *
 * @return array{show: array<string, mixed>, seasons: array<int, array<string, mixed>>}
 */
function longShowTreeFixture(int $count): array
{
    $show = jsonFixture('tmdb/tv');
    $show['number_of_seasons'] = $count;
    $show['seasons'] = [];
    $seasons = [];

    foreach (range(1, $count) as $number) {
        $date = sprintf('%d-04-01', 1990 + $number);
        $show['seasons'][] = ['id' => $number, 'season_number' => $number, 'name' => "Season {$number}", 'episode_count' => 2, 'air_date' => $date];
        $seasons[$number] = ['id' => $number, 'name' => "Season {$number}", 'air_date' => $date, 'season_number' => $number, 'episodes' => [
            ['episode_number' => 1, 'name' => 'One', 'air_date' => $date, 'runtime' => 50],
            ['episode_number' => 2, 'name' => 'Two', 'air_date' => $date, 'runtime' => 50],
        ]];
    }

    return ['show' => $show, 'seasons' => $seasons];
}

test('storing and refreshing a show costs at most 2 + ceil(seasons / 20) requests', function (array $tree) {
    $requests = 0;
    fakeShowTreeEndpoints($tree, $requests);
    $budget = 2 + (int) ceil(count($tree['seasons']) / TmdbPayload::APPEND_LIMIT);

    $show = app(TitleStore::class)->persist('show', '1399', 'en');
    $stored = $requests;

    // The daily refresh finds the day-old answers expired.
    Cache::flush();
    $requests = 0;
    app(TitleStore::class)->refresh($show, 'en');
    $refreshed = $requests;

    $requests = 0;
    app(ProviderCacheContext::class)->fresh(fn (): array => app(TitleStore::class)->refresh($show, 'en'));

    expect($stored)->toBeLessThanOrEqual($budget)
        ->and($refreshed)->toBeLessThanOrEqual($budget)
        ->and($requests)->toBeLessThanOrEqual($budget);
})->with([
    'four seasons, one undated' => fn (): array => showTreeFixture(),
    'twenty-five seasons' => fn (): array => longShowTreeFixture(25),
]);
