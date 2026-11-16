<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\ImportSourceRegistry;
use App\Jobs\RunImport;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * iCheckMovies is exercised on the shapes in tests/Fixtures/transfer/icheckmovies
 * (see the README there).
 */

/** TMDB knows The Matrix and The Shawshank Redemption by their IMDb ids; nothing else. */
function fakeICheckMoviesLookups(): void
{
    Http::fake([
        '*/find/tt0133093*' => Http::response(['movie_results' => [['id' => 603]], 'tv_results' => []]),
        '*/find/tt0111161*' => Http::response(['movie_results' => [['id' => 278]], 'tv_results' => []]),
        '*/search/*' => Http::response(['page' => 1, 'results' => [], 'total_pages' => 0, 'total_results' => 0]),
    ]);
}

test('an export of checks offers checks, favorites and the watchlist, and no list', function () {
    $manifest = app(ImportSourceRegistry::class)->get('icheckmovies')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/icheckmovies/checks.csv')));

    expect(array_map(fn (ManifestCategory $category) => [$category->key, $category->count], $manifest->categories))->toBe([
        ['history', 2],
        ['favorites', 1],
        ['watchlist', 1],
    ])
        ->and($manifest->lists)->toBe([]);
});

test('an export of a list offers it as a list named as the file was', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('settings.import.analyze', ['source' => 'icheckmovies']), [
            'file' => new UploadedFile(base_path('tests/Fixtures/transfer/icheckmovies/top-films.csv'), 'Top Films.csv', 'text/csv', null, true),
        ])
        ->assertOk()
        ->assertJsonPath('lists.0.name', 'Top Films')
        ->assertJsonPath('lists.0.itemCount', 2);
});

test('iCheckMovies checks come over dated by the check, with favorites and the watchlist', function () {
    fakeICheckMoviesLookups();

    $user = User::factory()->create();
    $matrix = MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $shawshank = MediaItem::factory()->movie()->create(['provider_ref' => '278']);

    $taskRun = runImportFile($user, 'icheckmovies', putTransferFixture('icheckmovies', 'checks.csv'), new ImportSelection(['history', 'favorites', 'watchlist']));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['Nowhere (2020): no match for an entry without ids, nor by name.'])
        ->and($user->plays()->where('media_item_id', $matrix->id)->value('watched_at')?->toDateString())->toBe('2015-10-12')
        ->and($user->favorites()->pluck('media_item_id')->all())->toBe([$matrix->id])
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$shawshank->id]);
});

test('the films of an exported list go on a list of their own', function () {
    fakeICheckMoviesLookups();

    $user = User::factory()->create();
    $matrix = MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $shawshank = MediaItem::factory()->movie()->create(['provider_ref' => '278']);

    $path = putTransferFixture('icheckmovies', 'top-films.csv');
    $taskRun = TaskRun::queueTask('import-icheckmovies', $user->id);
    app()->call([
        new RunImport($taskRun, 'icheckmovies', ImportInput::file(Storage::disk('local')->path($path), 'Top Films.csv'), new ImportSelection([], ['list'])),
        'handle',
    ]);

    expect($user->lists()->where('title', 'Top Films')->firstOrFail()->items()->orderBy('id')->pluck('media_item_id')->all())->toBe([$shawshank->id, $matrix->id])
        ->and($user->plays()->count())->toBe(0);
});

test('a Criticker export is not an iCheckMovies one', function () {
    $manifest = app(ImportSourceRegistry::class)->get('icheckmovies')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/criticker/rankings.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not an iCheckMovies export.');
});
