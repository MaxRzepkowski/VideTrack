<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * Anime-Planet is exercised on the shapes in tests/Fixtures/transfer/anime-planet
 * (see the README there).
 */

function animePlanetEverything(): ImportSelection
{
    return new ImportSelection(['read', 'reading', 'stalled', 'want to read', 'dropped']);
}

test('Anime-Planet is only offered with the manga module', function () {
    expect(app(ImportSourceRegistry::class)->has('anime-planet'))->toBeFalse();
});

test('an Anime-Planet manga list offers its statuses; "won\'t read" stays behind', function () {
    enableMangaModule();

    $manifest = app(ImportSourceRegistry::class)->get('anime-planet')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/anime-planet/export-manga.json')));

    expect(array_map(fn (ManifestCategory $category) => [$category->key, $category->count], $manifest->categories))->toBe([
        ['read', 1],
        ['reading', 1],
        ['stalled', 0],
        ['want to read', 1],
        ['dropped', 0],
    ]);
});

test('an Anime-Planet anime list is refused, as an anime found by its name lands on the wrong show', function () {
    enableMangaModule();

    $manifest = app(ImportSourceRegistry::class)->get('anime-planet')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/anime-planet/export-anime.json')));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('This is an Anime-Planet anime list. Only manga lists can be imported.');
});

test('Anime-Planet manga is found by its exact title, English ones included', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/*' => Http::response(jsonFixture('mal/search'))]);

    $user = User::factory()->create();
    $berserk = MediaItem::factory()->manga()->create(['provider_ref' => '2']);
    $solo = MediaItem::factory()->manga()->create(['provider_ref' => '121496']);

    $taskRun = runImportFile($user, 'anime-planet', putTransferFixture('anime-planet', 'export-manga.json'), animePlanetEverything());

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['Nowhere Manga: no match for an entry without ids, nor by name.'])
        ->and($user->plays()->where('media_item_id', $berserk->id)->value('watched_at')?->toDateString())->toBe('2023-03-01')
        ->and($user->ratings()->where('media_item_id', $berserk->id)->value('rating'))->toBe(9);

    $state = $user->mediaStates()->where('media_item_id', $solo->id)->firstOrFail();

    expect($state->status)->toBe(MediaUserStatus::InProgress)
        ->and($state->progress)->toBe(120);
});

test('a file that is not an Anime-Planet export is refused', function () {
    enableMangaModule();

    expect(app(ImportSourceRegistry::class)->get('anime-planet')->analyze(ImportInput::file(base_path('tests/Fixtures/transfer/criticker/rankings.csv')))->error)
        ->toBe('The file is not an Anime-Planet list export.');
});
