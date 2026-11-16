<?php

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Anime\MalListSource;
use App\Integrations\Anime\MalXmlSource;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A MyAnimeList export put next to the anime id map, gzipped as MyAnimeList
 * hands it out unless the test says otherwise. The disk is the one
 * seedAnimeIdMap() fakes, so the map is seeded first.
 */
function putMalExport(string $contents, bool $gzip = true): string
{
    $path = $gzip ? 'imports/animelist.xml.gz' : 'imports/animelist.xml';
    Storage::disk('local')->put($path, $gzip ? (string) gzencode($contents) : $contents);

    return $path;
}

function malFixture(string $name): string
{
    return (string) file_get_contents(fixture("transfer/mal/{$name}"));
}

test('a MyAnimeList XML file is offered without a MAL client id', function () {
    expect(app(ImportSourceRegistry::class)->find(MalXmlSource::CODE))->toBeInstanceOf(MalXmlSource::class)
        ->and(app(ImportSourceRegistry::class)->has(MalListSource::CODE))->toBeFalse();
});

test('an anime list export ticks watched seasons and places films through the anime id map', function () {
    seedAnimeIdMap();
    Http::fake(tmdbAnimeFakes());

    ['show' => $show, 'film' => $film] = animeCatalogue();
    $user = User::factory()->create();

    $taskRun = runImportFile($user, MalXmlSource::CODE, putMalExport(malFixture('animelist.xml')));

    // Skipped: the OVA, the anime outside the map, and the third episode TMDB does not have.
    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 3', 'ratings: 2', 'skipped entries: 3');

    $episodes = $user->plays()->whereHas('mediaItem', fn (Builder $query) => $query->where('type', MediaKind::Episode->value))->get();

    expect($episodes)->toHaveCount(2)
        ->and($episodes->every(fn (Play $play): bool => $play->watched_at->toDateString() === '2021-05-01'))->toBeTrue()
        ->and($user->ratings()->where('media_item_id', $show->id)->value('rating'))->toBe(9)
        ->and($user->plays()->where('media_item_id', $film->id)->firstOrFail()->watched_at->toDateString())->toBe('2020-01-02')
        ->and($user->ratings()->where('media_item_id', $film->id)->value('rating'))->toBe(10);

    expect($taskRun->errors)->toContain(
        'Shingeki no Kyojin OVA (MAL 18397): a special without an id of its own in the anime id map.',
        'Unknown Anime (MAL 99999): not in the anime id map, so it has no TVDB or IMDb id to go by.',
    );
});

test('a manga list export reads chapters, the score and plans', function () {
    enableMangaModule();
    seedAnimeIdMap();

    $reading = MediaItem::factory()->manga()->create(['provider_ref' => '44347']);
    $planned = MediaItem::factory()->manga()->create(['provider_ref' => '2']);
    $user = User::factory()->create();

    $taskRun = runImportFile($user, MalXmlSource::CODE, putMalExport(malFixture('mangalist.xml'), gzip: false));

    $state = $user->mediaStates()->where('media_item_id', $reading->id)->firstOrFail();

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($state->status)->toBe(MediaUserStatus::InProgress)
        ->and($state->progress)->toBe(120)
        ->and($user->ratings()->where('media_item_id', $reading->id)->value('rating'))->toBe(8)
        ->and($user->watchlist()->items()->where('media_item_id', $planned->id)->exists())->toBeTrue();
});

test('the preview counts what the file holds', function () {
    seedAnimeIdMap();

    $manifest = app(ImportSourceRegistry::class)->get(MalXmlSource::CODE)
        ->analyze(ImportInput::file(Storage::disk('local')->path(putMalExport(malFixture('animelist.xml')))));

    // A watched season is two rows (its episodes, its rating), a film one,
    // and each entry the map cannot place one: the run reports it as anime.
    expect($manifest->valid)->toBeTrue()
        ->and(collect($manifest->categories)->pluck('count', 'key')->all())->toBe(['anime' => 5, 'manga' => 0, 'unplaced' => 0]);
});

test('a file that is not a MyAnimeList export fails the run', function (string $contents) {
    seedAnimeIdMap();

    $taskRun = runImportFile(User::factory()->create(), MalXmlSource::CODE, putMalExport($contents));

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('The file is not a MyAnimeList XML export.');
})->with([
    'another XML' => ['<?xml version="1.0"?><library><anime><series_animedb_id>1</series_animedb_id></anime></library>'],
    'not XML at all' => ['media_id,source'.PHP_EOL.'1,tmdb'],
    'cut off halfway' => [fn () => substr(malFixture('animelist.xml'), 0, 1500)],
]);

test('an entity the file declares is not followed out of it', function () {
    seedAnimeIdMap();
    $secret = scratchPath('secret.txt');
    file_put_contents($secret, 'Not for the import');

    $xml = '<?xml version="1.0"?><!DOCTYPE myanimelist [<!ENTITY leak SYSTEM "file://'.$secret.'">]>'
        .'<myanimelist><anime><series_animedb_id>99999</series_animedb_id><series_title>Leak &leak;</series_title>'
        .'<my_status>Plan to Watch</my_status></anime></myanimelist>';

    $taskRun = runImportFile(User::factory()->create(), MalXmlSource::CODE, putMalExport($xml));

    // Read, and placed nowhere, with the entity left as it stood.
    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['Leak (MAL 99999): not in the anime id map, so it has no TVDB or IMDb id to go by.']);
});

test('a day known only in part lands on its first day', function () {
    seedAnimeIdMap();
    Http::fake(tmdbAnimeFakes());

    ['film' => $film] = animeCatalogue();
    $user = User::factory()->create();
    $entry = fn (int $id, string $status, int $watched, string $start, string $finish) => "<anime><series_animedb_id>{$id}</series_animedb_id>"
        ."<my_watched_episodes>{$watched}</my_watched_episodes><my_start_date>{$start}</my_start_date>"
        ."<my_finish_date>{$finish}</my_finish_date><my_score>0</my_score><my_status>{$status}</my_status></anime>";

    runImportFile($user, MalXmlSource::CODE, putMalExport('<myanimelist>'
        .$entry(16498, 'Watching', 1, '2021-04-00', '0000-00-00')
        .$entry(164, 'Completed', 1, '0000-00-00', '2019-00-00')
        .'</myanimelist>'));

    $episode = $user->plays()->whereHas('mediaItem', fn (Builder $query) => $query->where('type', MediaKind::Episode->value))->sole();

    expect($episode->watched_at->toDateString())->toBe('2021-04-01')
        ->and($user->plays()->where('media_item_id', $film->id)->sole()->watched_at->toDateString())->toBe('2019-01-01');
});

test('an entry without an id or with a status MyAnimeList does not write is named in the report', function () {
    seedAnimeIdMap();

    $taskRun = runImportFile(User::factory()->create(), MalXmlSource::CODE, putMalExport('<myanimelist>'
        .'<anime><series_title>Nameless</series_title><my_status>Completed</my_status></anime>'
        .'<anime><series_animedb_id>164</series_animedb_id><series_title>Mononoke Hime</series_title><my_status>Rewatching</my_status></anime>'
        .'</myanimelist>'));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe([
            'Nameless: the file gives no MyAnimeList id for it.',
            'Mononoke Hime (MAL 164): its list status is not one MyAnimeList writes.',
        ]);
});

test('a file that unpacks past the limit stops at analysis', function () {
    config(['tracker.imports.max_unpacked_megabytes' => 1]);
    seedAnimeIdMap();

    $path = putMalExport('<myanimelist>'.str_repeat('<!-- padding -->', 80_000).'</myanimelist>');
    $manifest = app(ImportSourceRegistry::class)->get(MalXmlSource::CODE)->analyze(ImportInput::file(Storage::disk('local')->path($path)));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('A file in this export is too large to import.');
});
