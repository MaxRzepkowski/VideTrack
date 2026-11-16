<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const PLAYBACK_REPORTING_FIXTURE = 'PlaybackReportingBackup-20240510-120000.tsv';

const PLAYBACK_REPORTING_ME = '3f2a9c1e0b7d4e6f8a1b2c3d4e5f6a7b';

/**
 * The fixture's titles in the catalog with their runtimes, and TMDB's
 * search knowing them by name.
 *
 * @return array{matrix: MediaItem, pilot: MediaItem, lostReel: MediaItem}
 */
function playbackReportingCatalog(): array
{
    $show = MediaItem::factory()->show()->create(['provider_ref' => '1396', 'title' => 'Breaking Bad']);
    $season = MediaItem::factory()->season($show, 1)->create();

    $catalog = [
        'matrix' => MediaItem::factory()->movie()->create(['provider_ref' => '603', 'title' => 'The Matrix', 'runtime' => 136]),
        'pilot' => MediaItem::factory()->episode($season, 1, '2008-01-20')->create(['runtime' => 58]),
        'lostReel' => MediaItem::factory()->movie()->create(['provider_ref' => '999001', 'title' => 'Lost Reel', 'runtime' => null]),
    ];

    Http::fake(['*/search/multi*' => tmdbSearchAnswer([
        'The Matrix' => ['id' => 603, 'media_type' => 'movie', 'title' => 'The Matrix', 'release_date' => '1999-03-31'],
        'Breaking Bad' => ['id' => 1396, 'media_type' => 'tv', 'name' => 'Breaking Bad', 'first_air_date' => '2008-01-20'],
        'Lost Reel' => ['id' => 999001, 'media_type' => 'movie', 'title' => 'Lost Reel', 'release_date' => '2001-01-01'],
    ])]);

    return $catalog;
}

test('a Playback Reporting backup offers the server\'s users, none of them ticked', function () {
    $manifest = app(ImportSourceRegistry::class)->get('jellyfin-playback-reporting')
        ->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/jellyfin/'.PLAYBACK_REPORTING_FIXTURE)));

    expect($manifest->valid)->toBeTrue()
        ->and($manifest->preselected)->toBeFalse()
        // Music is not tracked, and a line of the wrong width is not the plugin's.
        ->and(array_map(fn ($category) => [$category->key, $category->count], $manifest->categories))->toBe([
            [PLAYBACK_REPORTING_ME, 5],
            ['0123456789abcdef0123456789abcdef', 1],
        ]);
});

test('a Playback Reporting backup brings over the chosen user\'s playbacks that cover the runtime', function () {
    $user = User::factory()->create();
    $catalog = playbackReportingCatalog();

    putTransferFixture('jellyfin', PLAYBACK_REPORTING_FIXTURE);
    $taskRun = runImportFile($user, 'jellyfin-playback-reporting', 'imports/'.PLAYBACK_REPORTING_FIXTURE, new ImportSelection([PLAYBACK_REPORTING_ME]));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 2', 'skipped entries: 3')
        ->and($taskRun->errors)->toBe([
            // 600 seconds of a 136-minute film.
            'The Matrix: the playback covers 7% of the runtime, short of '.config('tracker.scrobble.completion_threshold').'%.',
            'Breaking Bad - s00e00 - Behind the Scenes: no season and episode number in the name.',
            'Lost Reel: the runtime is unknown, so the playback cannot count as watched.',
        ]);

    expect($user->plays()->where('media_item_id', $catalog['matrix']->id)->value('watched_at')->toDateTimeString())->toBe('2024-05-01 21:03:11')
        // The episode is its show's, found by the show's name, by its numbers.
        ->and($user->plays()->where('media_item_id', $catalog['pilot']->id)->exists())->toBeTrue()
        // Nobody else's viewing came along.
        ->and(Play::query()->count())->toBe(2);
});

test('a playback the webhook already logged minutes apart is not a second viewing', function () {
    $user = User::factory()->create();
    $catalog = playbackReportingCatalog();
    Play::factory()->for($user)->create(['media_item_id' => $catalog['matrix']->id, 'watched_at' => '2024-05-01 23:20:00']);
    projectLibrary($user);

    putTransferFixture('jellyfin', PLAYBACK_REPORTING_FIXTURE);
    runImportFile($user, 'jellyfin-playback-reporting', 'imports/'.PLAYBACK_REPORTING_FIXTURE, new ImportSelection([PLAYBACK_REPORTING_ME]));

    expect($user->plays()->where('media_item_id', $catalog['matrix']->id)->count())->toBe(1);
});

test('importing the same backup twice adds nothing', function () {
    $user = User::factory()->create();
    playbackReportingCatalog();
    $selection = new ImportSelection([PLAYBACK_REPORTING_ME]);

    putTransferFixture('jellyfin', PLAYBACK_REPORTING_FIXTURE);
    runImportFile($user, 'jellyfin-playback-reporting', 'imports/'.PLAYBACK_REPORTING_FIXTURE, $selection);
    putTransferFixture('jellyfin', PLAYBACK_REPORTING_FIXTURE);
    $again = runImportFile($user, 'jellyfin-playback-reporting', 'imports/'.PLAYBACK_REPORTING_FIXTURE, $selection);

    expect($again->summary)->toContain('plays: 0');
});

test('a CSV is not a Playback Reporting backup', function () {
    $manifest = app(ImportSourceRegistry::class)->get('jellyfin-playback-reporting')
        ->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/imdb/list.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a Playback Reporting backup, or it holds no films or episodes.');
});
