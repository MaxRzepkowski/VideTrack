<?php

use App\Catalog\MediaKind;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Netflix is exercised on the shapes in tests/Fixtures/transfer/netflix (see
 * the README there).
 */

/** TMDB's search knowing Roma and Game of Thrones by name, and Game of Thrones' two seasons. */
function fakeNetflixCatalog(): void
{
    $show = jsonFixture('tmdb/tv');
    $seasons = [1 => jsonFixture('tmdb/season'), 2 => jsonFixture('tmdb/season2'), 0 => jsonFixture('tmdb/season0')];

    Http::fake([
        '*/search/multi*' => tmdbSearchAnswer([
            'Roma' => ['id' => 426426, 'media_type' => 'movie', 'title' => 'Roma', 'release_date' => '2018-08-30'],
            'Game of Thrones' => ['id' => 1399, 'media_type' => 'tv', 'name' => 'Game of Thrones', 'first_air_date' => '2011-04-17'],
        ]),
        'api.themoviedb.org/3/tv/1399*' => function (Request $request) use ($show, $seasons) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (preg_match('#/season/(\d+)$#', $path, $match) === 1) {
                return Http::response($seasons[(int) $match[1]] ?? ['status_code' => 34], isset($seasons[(int) $match[1]]) ? 200 : 404);
            }

            foreach (explode(',', (string) ($request->data()['append_to_response'] ?? '')) as $part) {
                if (preg_match('#^season/(\d+)$#', $part, $match) === 1 && isset($seasons[(int) $match[1]])) {
                    $show[$part] = $seasons[(int) $match[1]];
                }
            }

            return Http::response($show);
        },
        ...providerDictionaryFakes(),
    ]);
}

test('Netflix playbacks are offered per profile, none ticked, trailers left out and a title resumed the same day counted once', function () {
    $manifest = app(ImportSourceRegistry::class)->get('netflix')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/netflix/ViewingActivity.csv')));

    expect(array_map(fn (ManifestCategory $category) => [$category->key, $category->label, $category->count, $category->preselected], $manifest->categories))->toBe([
        ['profile:Alex', 'Alex', 5, false],
        ['profile:Sam', 'Sam', 1, false],
    ]);
});

test('a Netflix profile\'s films come over by name, its episodes by their show and title', function () {
    fakeNetflixCatalog();

    $user = User::factory()->create();
    $roma = MediaItem::factory()->movie()->create(['provider_ref' => '426426', 'title' => 'Roma', 'runtime' => 135]);

    $taskRun = runImportFile($user, 'netflix', putTransferFixture('netflix', 'ViewingActivity.csv'), new ImportSelection(['profile:Alex']));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe([
            // A glance of a minute and a half.
            'Game of Thrones: Season 1: The Kingsroad: the playback covers 2% of the runtime, short of '.config('tracker.scrobble.completion_threshold').'%.',
            'Nowhere Film: no match for an entry without ids, nor by name.',
        ])
        ->and($user->plays()->where('media_item_id', $roma->id)->value('watched_at')?->toDateTimeString())->toBe('2024-03-09 21:15:02');

    $episodes = $user->plays()->with('mediaItem')->get()->filter(fn ($play) => $play->mediaItem->type === MediaKind::Episode->value)
        ->map(fn ($play) => $play->mediaItem->title)->sort()->values()->all();

    // Forty minutes and a resumed twenty of the same evening are one viewing
    // of an hour-long episode; "Part 2" is the season TMDB numbers 2.
    expect($episodes)->toBe(['The North Remembers', 'Winter Is Coming']);
});

test('the short history from the Netflix website is refused for what it lacks', function () {
    $manifest = app(ImportSourceRegistry::class)->get('netflix')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/netflix/NetflixViewingHistory.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toStartWith('This is the short viewing history from the Netflix website');
});

test('the viewing activity is read from the ZIP Netflix sends', function () {
    $path = putZip(['CONTENT_INTERACTION/ViewingActivity.csv' => (string) file_get_contents(base_path('tests/Fixtures/transfer/netflix/ViewingActivity.csv'))]);

    $manifest = app(ImportSourceRegistry::class)->get('netflix')->analyze(ImportInput::file(Storage::disk('local')->path($path)));

    expect($manifest->valid)->toBeTrue()
        ->and(array_column(array_map(fn (ManifestCategory $category) => $category->toArray(), $manifest->categories), 'count'))->toBe([5, 1]);
});

test('a Netflix playback without a title or a readable start time is reported, not dropped', function () {
    Http::fake();
    Storage::fake('local');
    Storage::disk('local')->put('imports/ViewingActivity.csv', "Profile Name,Start Time,Duration,Title,Supplemental Video Type\nAlex,yesterday,01:00:00,Roma,\nAlex,2024-03-09 21:15:02,01:00:00,,\n");

    $taskRun = runImportFile(User::factory()->create(), 'netflix', 'imports/ViewingActivity.csv', new ImportSelection(['profile:Alex']));

    expect($taskRun->errors)->toBe([
        'Roma: a Netflix playback without a title or a readable start time.',
        '2024-03-09 21:15:02: a Netflix playback without a title or a readable start time.',
    ]);
    Http::assertNothingSent();
});
