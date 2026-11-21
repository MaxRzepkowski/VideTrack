<?php

use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Import\Rows\SkipRow;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const WETRAKR_HEADER = 'title,year,type,tmdb_id,imdb_id,show_title,season_number,episode_number';

/** A WeTrakr moment as its export writes one. */
function weTrakrMoment(string $day): string
{
    return (new DateTimeImmutable("{$day} 20:00:00", new DateTimeZone('UTC')))->format('D M d Y H:i:s').' GMT+0000 (Coordinated Universal Time)';
}

/**
 * A WeTrakr export in the shape of the samples users shared: the tracklog
 * split in two parts, a show named only by its episodes' rows and one the
 * export carries a row for.
 *
 * @return array<string, string>
 */
function weTrakrExport(string $showRef): array
{
    $at = weTrakrMoment(...);

    return [
        'tracklog_1.csv' => WETRAKR_HEADER.",status,tracked_at,updated_at\n"
            ."The Matrix,1999,movie,603,tt0133093,,,,watched,{$at('2024-01-05')},{$at('2026-04-03')}\n"
            ."Dune,2021,movie,438631,tt1160419,,,,watched,,{$at('2026-04-03')}\n"
            ."Escape to Witch Mountain,1975,movie,14821,tt0072951,,,,plantowatch,{$at('2023-03-23')},{$at('2026-04-03')}\n"
            ."Marketplace,,show,12009,tt0251520,,,,discarded,{$at('2022-06-21')},{$at('2026-04-03')}\n",
        'tracklog_2.csv' => WETRAKR_HEADER.",status,tracked_at,updated_at\n"
            ."Some Show,,show,{$showRef},tt9000001,,,,watching,{$at('2024-02-25')},{$at('2026-04-03')}\n"
            ."Pilot,,episode,555001,tt9000002,Some Show,1,1,watched,{$at('2024-02-25')},{$at('2026-04-03')}\n"
            ."Ghost,,episode,777002,tt9000004,Elsewhere,1,2,watched,,{$at('2026-04-03')}\n",
        'ratings.csv' => WETRAKR_HEADER.",rating,rated_at\n"
            ."The Matrix,1999,movie,603,tt0133093,,,,9,{$at('2024-01-06')}\n"
            ."Pilot,,episode,555001,tt9000002,Some Show,1,1,8,{$at('2024-02-26')}\n"
            ."Some Show,,season,4576,tt9000001,,9352,,7,{$at('2024-02-27')}\n",
        'lists.csv' => "list_name,list_description,title,year,type,tmdb_id,imdb_id,rank,created_at\n"
            ."Weekend,\"Short, light, late\",The Matrix,1999,movie,603,tt0133093,2,{$at('2026-04-08')}\n"
            ."Weekend,\"Short, light, late\",Pilot,,episode,555001,,1,{$at('2026-04-08')}\n"
            ."Weekend,\"Short, light, late\",Season 1,,season,4576,,3,{$at('2026-04-08')}\n"
            ."Weekend,\"Short, light, late\",Mystery,,episode,999999,,4,{$at('2026-04-08')}\n",
        'favorites.csv' => "title,year,type,tmdb_id,imdb_id,created_at\nThe Matrix,1999,movie,603,tt0133093,{$at('2026-04-01')}\n",
        'notes.csv' => WETRAKR_HEADER.",text,privacy,spoiler,created_at\nPilot,,episode,555001,tt9000002,Some Show,1,1,A note,private,0,{$at('2026-04-09')}\n",
        'profile.csv' => "username,email,display_name,joined_at,plan,timezone,country\nalex,alex@example.com,alex,{$at('2026-01-01')},free,UTC,US\n",
    ];
}

function weTrakrEverything(): ImportSelection
{
    return new ImportSelection(['history', 'ratings', 'watchlist', 'dropped', 'favorites'], ['Weekend']);
}

test('a WeTrakr export offers what each of its files holds', function () {
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);
    $path = putZip(weTrakrExport($show->provider_ref));

    $manifest = app(ImportSourceRegistry::class)->get('wetrakr')->analyze(ImportInput::file(Storage::disk('local')->path($path)));

    expect(array_map(fn ($category) => [$category->key, $category->count], $manifest->categories))->toBe([
        ['history', 4],
        ['ratings', 3],
        ['watchlist', 1],
        ['dropped', 1],
        ['favorites', 1],
    ])
        ->and(array_map(fn ($list) => [$list->id, $list->itemCount], $manifest->lists))->toBe([['Weekend', 4]]);
});

test('a WeTrakr export brings over the history, ratings, watchlist, drops, favorites and a list', function () {
    Http::fake([
        '*/find/tt9000004*' => Http::response(['movie_results' => [], 'tv_results' => [], 'tv_episode_results' => []]),
        '*/search/*' => Http::response(['page' => 1, 'results' => [], 'total_pages' => 1, 'total_results' => 0]),
    ]);
    $user = User::factory()->create();
    $matrix = MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $dune = MediaItem::factory()->movie()->create(['provider_ref' => '438631', 'release_date' => '2021-10-22']);
    $witch = MediaItem::factory()->movie()->create(['provider_ref' => '14821']);
    $marketplace = MediaItem::factory()->show()->create(['provider_ref' => '12009']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);
    $pilot = MediaItem::query()->where('parent_id', MediaItem::query()->where('parent_id', $show->id)->value('id'))->orderBy('number')->firstOrFail();

    $taskRun = runImportFile($user, 'wetrakr', putZip(weTrakrExport($show->provider_ref)), weTrakrEverything());

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe([
            'Elsewhere S01E02: no match for imdb tt9000004, nor by name.',
            'Some Show: a season, and the export does not say which one.',
            'Season 1: a season, and the export does not say which one.',
            'Mystery: nothing in the export says which title this is.',
        ]);

    // A viewing WeTrakr kept no date for lands on the title's release day.
    expect($user->plays()->where('media_item_id', $matrix->id)->firstOrFail()->watched_at->toDateString())->toBe('2024-01-05')
        ->and($user->plays()->where('media_item_id', $dune->id)->firstOrFail()->watched_at->toDateString())->toBe('2021-10-22')
        ->and($user->plays()->where('media_item_id', $pilot->id)->firstOrFail()->watched_at->toDateString())->toBe('2024-02-25')
        ->and($user->ratings()->pluck('rating', 'media_item_id')->all())->toBe([$matrix->id => 9, $pilot->id => 8])
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$witch->id])
        ->and($user->plays()->where('media_item_id', $marketplace->id)->where('outcome', PlayOutcome::Dropped->value)->exists())->toBeTrue()
        ->and($user->favorites()->pluck('media_item_id')->all())->toBe([$matrix->id]);

    $list = $user->lists()->where('title', 'Weekend')->firstOrFail();

    // The episode the list names by its id alone is found through the tracklog, and the list keeps WeTrakr's order.
    expect($list->description)->toBe('Short, light, late')
        ->and($list->items()->orderBy('position')->pluck('media_item_id')->all())->toBe([$pilot->id, $matrix->id]);

    // The episode of a show the export has no row for was asked about by its own IMDb id.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'find/tt9000004'));
});

test('a show two rows name differently is not guessed from its name', function () {
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);
    $files = weTrakrExport($show->provider_ref);
    $files['lists.csv'] .= 'Weekend,,Some Show,,show,424242,,5,'.weTrakrMoment('2026-04-08')."\n";
    Http::fake(['*/find/*' => Http::response(['tv_episode_results' => [['show_id' => (int) $show->provider_ref, 'season_number' => 1, 'episode_number' => 1]]])]);

    $user = User::factory()->create();
    MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    MediaItem::factory()->movie()->create(['provider_ref' => '438631']);
    runImportFile($user, 'wetrakr', putZip($files), new ImportSelection(['history'], []));

    // With two ids for "Some Show", the pilot was found by its own IMDb id instead.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'find/tt9000002'));
});

test('importing the same WeTrakr export twice adds nothing', function () {
    Http::fake([
        '*/find/*' => Http::response(['movie_results' => [], 'tv_results' => [], 'tv_episode_results' => []]),
        '*/search/*' => Http::response(['page' => 1, 'results' => [], 'total_pages' => 1, 'total_results' => 0]),
    ]);
    $user = User::factory()->create();
    foreach (['603', '438631', '14821'] as $ref) {
        MediaItem::factory()->movie()->create(['provider_ref' => $ref]);
    }
    MediaItem::factory()->show()->create(['provider_ref' => '12009']);
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-02']]);

    runImportFile($user, 'wetrakr', putZip(weTrakrExport($show->provider_ref)), weTrakrEverything());
    $again = runImportFile($user, 'wetrakr', putZip(weTrakrExport($show->provider_ref)), weTrakrEverything());

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'favorites: 0', 'drops: 0', 'list items: 0');
});

test('a ZIP without WeTrakr files is not a WeTrakr export', function () {
    $path = putZip(['watched-history-1.json' => '[]']);

    $manifest = app(ImportSourceRegistry::class)->get('wetrakr')->analyze(ImportInput::file(Storage::disk('local')->path($path)));

    expect($manifest->valid)->toBeFalse()
        ->and($manifest->error)->toBe('The file is not a WeTrakr export.');
});

test('a list with a long name can be picked, and a row the CSV cannot read is reported once', function () {
    $name = str_repeat('Z', 70);
    $path = putZip([
        'lists.csv' => "list_name,list_description,title,year,type,tmdb_id,imdb_id,rank,created_at\n{$name},,The Matrix,1999,movie,603,,1,\n{$name},,,,show,,,2,\n",
        'tracklog.csv' => WETRAKR_HEADER.",status,tracked_at,updated_at\nBroken\n",
    ]);
    $source = app(ImportSourceRegistry::class)->get('wetrakr');
    $input = ImportInput::file(Storage::disk('local')->path($path));
    $manifest = $source->analyze($input);
    $list = $manifest->lists[0];

    expect(mb_strlen($list->id))->toBeLessThanOrEqual(ImportSelection::KEY_LENGTH);

    $skips = array_values(array_filter(iterator_to_array($source->rows($input, new ImportSelection(['history', 'watchlist', 'dropped'], [$list->id])), false), fn ($row) => $row instanceof SkipRow));

    expect(array_map(fn (SkipRow $skip) => [$skip->key, $skip->replace], $skips))->toBe([
        ['import.skip_csv_row_malformed', ['row' => 2]],
        // A row naming its list and nothing else is named as untitled.
        ['import.skip_wetrakr_unknown_place', ['label' => 'An entry without a title']],
    ]);
});
