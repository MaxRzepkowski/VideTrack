<?php

use App\Actions\Library\MarkWatched;
use App\Catalog\ChartOrder;
use App\Catalog\Sets\ChartRef;
use App\Catalog\Sets\SetKind;
use App\Library\WatchedAt;
use App\Models\CatalogSet;
use App\Models\CatalogSetTitle;
use App\Models\MediaItem;
use App\Models\User;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Sources\BestKnownUnseen;
use App\Suggestions\Sources\BiggestByDecade;
use App\Suggestions\Sources\BiggestByYear;
use App\Suggestions\Sources\TopRatedUnseen;
use App\Tasks\TaskName;
use Illuminate\Support\Facades\Http;

/**
 * A chart's set as fetched: the given refs from the top down.
 *
 * @param  list<string>  $refs
 */
function chartSet(ChartRef $chart, array $refs): CatalogSet
{
    $set = CatalogSet::factory()->create(['kind' => SetKind::Chart, 'ref' => $chart->key('tmdb')->ref, 'name' => 'chart']);

    foreach ($refs as $index => $ref) {
        CatalogSetTitle::factory()->create(['catalog_set_id' => $set->id, 'position' => $index + 1, 'type' => $chart->type, 'ref' => $ref, 'title' => "{$chart->type} {$ref}"]);
    }

    return $set;
}

test('the best known shelf asks for the all-time chart of every charted type, as deep as configured', function () {
    $bestKnown = config('tracker.suggestions.chart_depth.best_known');
    $topRated = config('tracker.suggestions.chart_depth.top_rated');
    $keys = app(BestKnownUnseen::class)->wantedSets(User::factory()->create());

    expect(array_map(fn ($key) => $key->id(), $keys))->toBe(["chart:tmdb:movie:most-voted:all:{$bestKnown}", "chart:tmdb:show:most-voted:all:{$bestKnown}"])
        ->and(array_map(fn ($key) => $key->ref, app(TopRatedUnseen::class)->wantedSets(User::factory()->create())))
        ->toBe(["movie:top-rated:all:{$topRated}", "show:top-rated:all:{$topRated}"]);
});

test('it puts forward what the user has not seen, by place, films and shows interleaved', function () {
    $user = User::factory()->create();
    $depth = config('tracker.suggestions.chart_depth.best_known');
    app(MarkWatched::class)->execute($user, MediaItem::factory()->movie()->create(['provider_ref' => '11']), WatchedAt::now());
    chartSet(new ChartRef('movie', ChartOrder::MostVoted, $depth), ['10', '11', '12']);
    chartSet(new ChartRef('show', ChartOrder::MostVoted, $depth), ['20']);

    $groups = app(BestKnownUnseen::class)->groups($user, 'en');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->key)->toBeNull()
        ->and(array_map(fn (Suggestion $suggestion): array => [$suggestion->media->title, $suggestion->reason], $groups[0]->suggestions))
        ->toBe([['movie 10', ['No. 1']], ['show 20', ['No. 1']], ['movie 12', ['No. 3']]]);
});

test('years come as subjects, the latest first, with how much of each the user has seen', function () {
    $user = User::factory()->create();
    $year = today()->year;
    $depth = config('tracker.suggestions.chart_depth.per_year');
    app(MarkWatched::class)->execute($user, MediaItem::factory()->movie()->create(['provider_ref' => '31']), WatchedAt::now());
    chartSet(new ChartRef('movie', ChartOrder::MostVoted, $depth, $year - 1, $year - 1), ['30', '31']);
    chartSet(new ChartRef('movie', ChartOrder::MostVoted, $depth, $year, $year), ['40']);
    chartSet(new ChartRef('show', ChartOrder::MostVoted, $depth, $year, $year), ['41']);
    // Everything seen: no subject.
    chartSet(new ChartRef('movie', ChartOrder::MostVoted, $depth, $year - 2, $year - 2), ['31']);

    $source = app(BiggestByYear::class);
    $groups = $source->groups($user, 'en');

    expect($source->wantedSets($user))->toHaveCount(BiggestByYear::YEARS * 2)
        ->and(array_map(fn ($group) => [$group->key, $group->title, $group->caption, count($group->suggestions)], $groups))
        ->toBe([
            ["{$year}-{$year}", (string) $year, '0 of 2 watched', 2],
            [($year - 1).'-'.($year - 1), (string) ($year - 1), '1 of 2 watched', 1],
        ])
        ->and($groups[0]->href)->toBeNull();
});

test('decades are named by their years and reach back to the first one charted', function () {
    $user = User::factory()->create();
    $depth = config('tracker.suggestions.chart_depth.per_decade');
    $first = BiggestByDecade::FIRST_DECADE;
    chartSet(new ChartRef('movie', ChartOrder::MostVoted, $depth, 1980, 1989), ['50']);

    $source = app(BiggestByDecade::class);
    $refs = array_map(fn ($key) => $key->ref, $source->wantedSets($user));

    expect($refs)->toContain("movie:most-voted:2020-2029:{$depth}", "show:most-voted:{$first}-".($first + 9).":{$depth}")
        ->and($refs)->not->toContain('movie:most-voted:'.($first - 10).'-'.($first - 1).":{$depth}")
        ->and(array_map(fn ($group) => $group->title, $source->groups($user, 'en')))->toBe(['1980–1989']);
});

test('the run reads a chart page by page, in the provider\'s order, down to its depth', function () {
    $page = fn (int $number, array $ids) => Http::response([
        'page' => $number,
        'total_pages' => 3,
        'total_results' => 6,
        'results' => array_map(fn (int $id) => ['id' => $id, 'title' => "Film {$id}", 'poster_path' => "/{$id}.jpg", 'release_date' => '1985-01-01', 'vote_count' => 9000 - $id], $ids),
    ]);
    Http::fake(fn ($request) => match (true) {
        ! str_contains($request->url(), 'discover/movie') || ($request->data()['primary_release_date.gte'] ?? null) !== '1980-01-01' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []]),
        default => $page((int) $request->data()['page'], [(int) $request->data()['page'] * 10 + 1, (int) $request->data()['page'] * 10 + 2]),
    });
    // Only the one chart is asked for, three titles deep.
    CatalogSet::factory()->unfetched()->create(['kind' => SetKind::Chart, 'ref' => 'movie:most-voted:1980-1989:3']);
    onlySuggestionSources([]);

    runScheduledTask(TaskName::RefreshSuggestionSets->value);

    $set = CatalogSet::query()->where('ref', 'movie:most-voted:1980-1989:3')->sole();
    $asked = Http::recorded()->map(fn (array $pair) => $pair[0])->filter(fn ($request) => ($request->data()['primary_release_date.gte'] ?? null) === '1980-01-01' && ($request->data()['primary_release_date.lte'] ?? null) === '1989-12-31');

    expect($set->titles->map(fn ($title) => [$title->position, $title->ref, $title->votes])->all())->toBe([[1, '11', 8989], [2, '12', 8988], [3, '21', 8979]])
        ->and($asked->map(fn ($request) => [$request->data()['sort_by'], $request->data()['page']])->values()->all())->toBe([['vote_count.desc', 1], ['vote_count.desc', 2]]);
});
