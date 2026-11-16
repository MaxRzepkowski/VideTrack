<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The fixture's shows and films in the catalog, and TMDB answering for
 * them: TVDB episode and show ids through `find`, the films by a search.
 *
 * @return array<string, MediaItem>
 */
function tvTimeCatalog(): array
{
    $catalog = [
        'treason' => makeShowTree([1 => array_fill(0, 5, '2022-12-01')]),
        'unit' => makeShowTree([1 => array_fill(0, 4, '2006-03-07')]),
        'halo' => makeShowTree([1 => array_fill(0, 8, '2022-03-24')]),
        'legion' => MediaItem::factory()->show()->create(['provider_ref' => '67195']),
        'lovecraft' => MediaItem::factory()->show()->create(['provider_ref' => '82816']),
        'monday' => MediaItem::factory()->movie()->create(['provider_ref' => '406990']),
        'sweepers' => MediaItem::factory()->movie()->create(['provider_ref' => '581389']),
        'heretic' => MediaItem::factory()->movie()->create(['provider_ref' => '1138194']),
    ];

    $episode = fn (MediaItem $show, int $number) => ['tv_episode_results' => [['show_id' => (int) $show->provider_ref, 'season_number' => 1, 'episode_number' => $number]]];
    $show = fn (MediaItem $show) => ['tv_results' => [['id' => (int) $show->provider_ref]]];
    $film = fn (int $id, string $title, string $released): array => ['id' => $id, 'media_type' => 'movie', 'title' => $title, 'release_date' => $released];

    Http::fake([
        '*/find/9476788*' => Http::response($episode($catalog['treason'], 5)),
        '*/find/306587*' => Http::response($episode($catalog['unit'], 4)),
        '*/find/366524*' => Http::response($show($catalog['halo'])),
        '*/find/320724*' => Http::response($show($catalog['legion'])),
        '*/find/357864*' => Http::response($show($catalog['lovecraft'])),
        '*/find/*' => Http::response(['movie_results' => [], 'tv_results' => [], 'tv_episode_results' => []]),
        // Searched by the slug of the English title, as TV Time keeps it.
        '*/search/multi*' => tmdbSearchAnswer([
            'what happened to monday' => $film(406990, 'What Happened to Monday', '2017-08-18'),
            'space sweepers' => $film(581389, 'Space Sweepers', '2021-02-05'),
            'heretic' => $film(1138194, 'Heretic', '2024-10-31'),
        ]),
    ]);

    return $catalog;
}

test('a TV Time package offers its history, what was saved for later and the film ratings', function () {
    $path = putZipFixture('tvtime/gdpr');

    $manifest = app(ImportSourceRegistry::class)->get('tvtime')->analyze(ImportInput::file(Storage::disk('local')->path($path)));

    expect($manifest->valid)->toBeTrue()
        ->and(array_map(fn ($category) => [$category->key, $category->count], $manifest->categories))->toBe([
            // Five v2 episode viewings (one without ids) and three film viewings; the older table's episode is v2's too.
            ['history', 8],
            // Legion saved for later, Lovecraft Country followed unwatched, Heretic saved for later.
            ['watchlist', 3],
            ['ratings', 3],
        ]);
});

test('a TV Time package places episodes by their TVDB ids and films by title and year', function () {
    $user = User::factory()->create();
    $catalog = tvTimeCatalog();

    $taskRun = runImportFile($user, 'tvtime', putZipFixture('tvtime/gdpr'), new ImportSelection(['history', 'watchlist', 'ratings']));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 7', 'ratings: 2', 'list items: 3', 'skipped entries: 2', '3 titles matched by name, not by id.')
        ->and($taskRun->errors)->toBe([
            'Nameless: neither the episode\'s TVDB id nor its show\'s and numbers.',
            'Heretic: a rating TV Time\'s star scale does not know.',
        ]);

    expect($user->plays()->where('media_item_id', episodeOf($catalog['treason'], 1, 5)->id)->value('watched_at')->toDateTimeString())->toBe('2024-11-02 11:53:58')
        // A rewatch is a second viewing of the same episode.
        ->and($user->plays()->where('media_item_id', episodeOf($catalog['unit'], 1, 4)->id)->count())->toBe(2)
        // No episode id: the show's TVDB id and the numbers lead there.
        ->and($user->plays()->where('media_item_id', episodeOf($catalog['halo'], 1, 8)->id)->exists())->toBeTrue()
        // The older table dates a film viewing by its epoch.
        ->and($user->plays()->where('media_item_id', $catalog['monday']->id)->orderBy('watched_at')->pluck('watched_at')->map->toDateTimeString()->all())
        ->toBe(['2022-04-10 11:26:55', '2023-11-14 22:13:20'])
        ->and($user->ratings()->where('media_item_id', $catalog['monday']->id)->value('rating'))->toBe(10)
        ->and($user->ratings()->where('media_item_id', $catalog['sweepers']->id)->value('rating'))->toBe(8)
        ->and($user->watchlist()->items()->pluck('media_item_id')->sort()->values()->all())
        ->toBe(collect([$catalog['legion']->id, $catalog['lovecraft']->id, $catalog['heretic']->id])->sort()->values()->all());

    // The film is searched by the English title TV Time sorts it by, not by its Korean name.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'search/multi') && ($request->data()['query'] ?? null) === 'space sweepers');
});

test('importing the same TV Time package twice adds nothing', function () {
    $user = User::factory()->create();
    tvTimeCatalog();
    $selection = new ImportSelection(['history', 'watchlist', 'ratings']);

    runImportFile($user, 'tvtime', putZipFixture('tvtime/gdpr'), $selection);
    $again = runImportFile($user, 'tvtime', putZipFixture('tvtime/gdpr'), $selection);

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'list items: 0');
});

test('a package without the tracking table is refused with the reason', function () {
    $taskRun = runImportFile(User::factory()->create(), 'tvtime', putZip(['auth-prod-login.csv' => "user_id,login\n0,anonymous\n"]), new ImportSelection(['history']));

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('The file is not a TV Time data package: it has no tracking-prod-records file.');
});
