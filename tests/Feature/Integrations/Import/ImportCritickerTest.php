<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * Criticker is exercised on the shape in tests/Fixtures/transfer/criticker
 * (see the README there).
 */

/** TMDB knows The Matrix and Breaking Bad by their IMDb ids; nothing else, by id or by name. */
function fakeCritickerLookups(): void
{
    Http::fake([
        '*/find/tt0133093*' => Http::response(['movie_results' => [['id' => 603]], 'tv_results' => []]),
        '*/find/tt0903747*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => 1396]]]),
        '*/find/*' => Http::response(['movie_results' => [], 'tv_results' => [], 'tv_episode_results' => []]),
        '*/search/*' => Http::response(['page' => 1, 'results' => [], 'total_pages' => 0, 'total_results' => 0]),
    ]);
}

test('Criticker scores come over; taking them for viewings waits for the user to tick it', function () {
    $manifest = app(ImportSourceRegistry::class)->get('criticker')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/criticker/rankings.csv')));

    expect(array_map(fn (ManifestCategory $category) => [$category->key, $category->count, $category->preselected], $manifest->categories))->toBe([
        ['ratings', 4, true],
        // A show taken for watched would be every episode at once.
        ['watched', 3, false],
    ]);
});

test('Criticker scores land on our scale, films and shows placed by their IMDb ids', function () {
    fakeCritickerLookups();

    $user = User::factory()->create();
    $matrix = MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $breakingBad = MediaItem::factory()->show()->create(['provider_ref' => '1396']);

    $taskRun = runImportFile($user, 'criticker', putTransferFixture('criticker', 'rankings.csv'), new ImportSelection(['ratings']));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe([
            'Unscored Film (2001): no score.',
            'Nowhere (2020): no match for an entry without ids, nor by name.',
        ])
        ->and($user->ratings()->pluck('rating', 'media_item_id')->all())->toBe([$matrix->id => 9, $breakingBad->id => 4])
        ->and($user->ratings()->where('media_item_id', $matrix->id)->value('created_at')?->toDateString())->toBe('2025-01-17')
        ->and($user->plays()->count())->toBe(0);
});

test('a scored Criticker film counts as watched, undated, when the user asks', function () {
    fakeCritickerLookups();

    $user = User::factory()->create();
    $matrix = MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    MediaItem::factory()->show()->create(['provider_ref' => '1396']);

    runImportFile($user, 'criticker', putTransferFixture('criticker', 'rankings.csv'), new ImportSelection(['watched']));

    expect($user->plays()->pluck('media_item_id')->all())->toBe([$matrix->id])
        ->and($user->ratings()->count())->toBe(0);
});

test('an IMDb export is not a Criticker one', function () {
    $manifest = app(ImportSourceRegistry::class)->get('criticker')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/imdb/list.csv')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a Criticker rankings export.');
});
