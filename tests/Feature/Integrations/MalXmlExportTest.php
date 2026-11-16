<?php

use App\Actions\Library\AddToList;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\LogProgress;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\SetMediaStatus;
use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Anime\MalAnimeExport;
use App\Integrations\Anime\MalMangaExport;
use App\Integrations\Anime\MalXmlSource;
use App\Library\Data\ListTarget;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A map of its own: Frieren (TVDB 100) whole in season 1 and split in
 * season 2 after episode 2, its season 3 claimed by two MAL ids at once;
 * one whole season each for three more shows; Mononoke Hime as a film and
 * a film two MAL ids share.
 */
function seedExportAnimeMap(): void
{
    seedAnimeIdMap([
        '1' => ['tvdb_id' => 100, 'tvdb_season' => 1, 'tvdb_epoffset' => 0, 'mal_id' => 1001],
        '2' => ['tvdb_id' => 100, 'tvdb_season' => 2, 'tvdb_epoffset' => 0, 'mal_id' => 1002],
        '3' => ['tvdb_id' => 100, 'tvdb_season' => 2, 'tvdb_epoffset' => 2, 'mal_id' => 1003],
        '4' => ['tvdb_id' => 100, 'tvdb_season' => 3, 'tvdb_epoffset' => 0, 'mal_id' => '1004,1005'],
        '5' => ['tvdb_id' => 300, 'tvdb_season' => 1, 'tvdb_epoffset' => 0, 'mal_id' => 3001],
        '6' => ['tvdb_id' => 400, 'tvdb_season' => 1, 'tvdb_epoffset' => 0, 'mal_id' => 4001],
        '7' => ['tvdb_id' => 500, 'tvdb_season' => 1, 'tvdb_epoffset' => 0, 'mal_id' => 5001],
        '8' => ['tvdb_epoffset' => 0, 'imdb_id' => 'tt0119698', 'mal_id' => 164],
        '9' => ['tvdb_epoffset' => 0, 'imdb_id' => 'tt0000002', 'mal_id' => '2001,2002'],
    ]);
}

/**
 * @param  array<int, list<string>>  $seasons
 */
function exportedShow(string $title, string $ref, int $tvdbId, array $seasons): MediaItem
{
    $show = showWithRefs($ref, $seasons);
    $show->update(['title' => $title, 'metadata' => ['tvdbId' => $tvdbId]]);
    MediaItem::query()->where('parent_id', $show->id)->each(fn (MediaItem $season) => $season->update(['title' => "Season {$season->number}"]));

    return $show;
}

function watchEpisode(User $user, MediaItem $show, int $season, int $episode, string $at): void
{
    app(MarkWatched::class)->execute($user, episodeOf($show, $season, $episode), WatchedAt::at(CarbonImmutable::parse($at)));
}

/**
 * The download parsed, its entries keyed by MAL id.
 *
 * @return array{info: array<string, string>, entries: array<int, array<string, string>>}
 */
function malDownload(User $user, string $format): array
{
    $content = test()->actingAs($user)->get(route('settings.export.download', $format))->assertOk()->streamedContent();
    $xml = simplexml_load_string($content);
    $entries = [];

    foreach ($xml->children() as $name => $node) {
        if ($name !== 'myinfo') {
            $fields = array_map('strval', iterator_to_array($node->children(), true));
            $entries[(int) ($fields['series_animedb_id'] ?? $fields['manga_mangadb_id'])] = $fields;
        }
    }

    return ['info' => array_map('strval', iterator_to_array($xml->myinfo->children(), true)), 'entries' => $entries];
}

/**
 * A viewer of anime in every shape the file can hold, and of a show that is no anime.
 *
 * @return array{frieren: MediaItem, film: MediaItem}
 */
function seedAnimeViewer(User $user): array
{
    $past = ['2023-09-29', '2023-10-06'];
    $frieren = exportedShow('Frieren', '9100', 100, [1 => $past, 2 => [...$past, '2024-01-01', '2027-01-01'], 3 => $past]);
    $planned = exportedShow('Dungeon Meshi', '9300', 300, [1 => $past]);
    $wanted = exportedShow('Pluto', '9400', 400, [1 => $past]);
    $dropped = exportedShow('Dorohedoro', '9500', 500, [1 => $past]);
    $drama = exportedShow('Not Anime', '9600', 600, [1 => $past]);
    $film = MediaItem::factory()->movie()->create(['title' => 'Princess Mononoke', 'provider_ref' => '128', 'metadata' => ['imdbId' => 'tt0119698']]);
    $shared = MediaItem::factory()->movie()->create(['title' => 'Shared', 'metadata' => ['imdbId' => 'tt0000002']]);

    watchEpisode($user, $frieren, 1, 1, '2024-03-01 20:00');
    watchEpisode($user, $frieren, 1, 2, '2024-03-02 20:00');
    watchEpisode($user, $frieren, 2, 1, '2024-04-01 20:00');
    watchEpisode($user, $frieren, 2, 2, '2024-04-02 20:00');
    watchEpisode($user, $frieren, 2, 3, '2024-04-03 20:00');
    watchEpisode($user, $frieren, 3, 1, '2024-05-01 20:00');
    app(RateItem::class)->execute($user, seasonOf($frieren, 1), 9);
    app(RateItem::class)->execute($user, $frieren, 7);
    app(AddToList::class)->execute($user, $user->watchlist(), ListTarget::item($planned->id));
    app(AddToList::class)->execute($user, $user->watchlist(), ListTarget::item(seasonOf($wanted, 1)->id));
    watchEpisode($user, $dropped, 1, 1, '2024-06-01 20:00');
    app(DropAttempt::class)->execute($user, $dropped, WatchedAt::at(CarbonImmutable::parse('2024-06-02 20:00')));
    watchEpisode($user, $drama, 1, 1, '2024-06-01 20:00');
    app(MarkWatched::class)->execute($user, $film, WatchedAt::at(CarbonImmutable::parse('2020-01-02 20:00')));
    app(RateItem::class)->execute($user, $film, 10);
    app(MarkWatched::class)->execute($user, $shared, WatchedAt::at(CarbonImmutable::parse('2020-01-02 20:00')));

    return ['frieren' => $frieren, 'film' => $film];
}

test('an anime list carries each season part the map names, and films, and nothing else', function () {
    seedExportAnimeMap();
    $user = User::factory()->create();
    seedAnimeViewer($user);

    ['info' => $info, 'entries' => $entries] = malDownload($user, MalAnimeExport::CODE);

    // Not the shared season 3, the shared film, the show-level Backlog or the drama.
    expect(array_keys($entries))->toBe([5001, 1001, 1002, 1003, 4001, 164])
        ->and($info)->toMatchArray([
            'user_name' => $user->username,
            'user_export_type' => '1',
            'user_total_anime' => '6',
            'user_total_watching' => '1',
            'user_total_completed' => '3',
            'user_total_dropped' => '1',
            'user_total_plantowatch' => '1',
        ]);
});

test('a season part reads its own episodes, and only a whole season carries the season\'s marks', function () {
    seedExportAnimeMap();
    $user = User::factory()->create();
    seedAnimeViewer($user);

    $entries = malDownload($user, MalAnimeExport::CODE)['entries'];

    // The season rated 9 and the show rated 7: only the season's goes, to the part that is all of it.
    expect($entries[1001])->toMatchArray([
        'series_title' => 'Frieren – Season 1',
        'my_status' => 'Completed',
        'my_watched_episodes' => '2',
        'series_episodes' => '2',
        'my_score' => '9',
        'my_start_date' => '2024-03-01',
        'my_finish_date' => '2024-03-02',
        'update_on_import' => '0',
    ])->and($entries[1002])->toMatchArray([
        'series_title' => 'Frieren – Season 2 (1–2)',
        'my_status' => 'Completed',
        'my_watched_episodes' => '2',
        'my_score' => '0',
    ])->and($entries[1003])->toMatchArray([
        // Episode 4 has not aired: watched as far as it goes, not completed.
        'series_title' => 'Frieren – Season 2 (3–4)',
        'my_status' => 'Watching',
        'my_watched_episodes' => '1',
        'series_episodes' => '2',
        'my_start_date' => '2024-04-03',
        'my_finish_date' => '0000-00-00',
    ])->and($entries[4001])->toMatchArray(['my_status' => 'Plan to Watch', 'my_watched_episodes' => '0'])
        ->and($entries[5001])->toMatchArray(['my_status' => 'Dropped', 'my_watched_episodes' => '1']);
});

test('a film goes as one episode watched, with its rating and day', function () {
    seedExportAnimeMap();
    $user = User::factory()->create();
    seedAnimeViewer($user);

    expect(malDownload($user, MalAnimeExport::CODE)['entries'][164])->toMatchArray([
        'series_title' => 'Princess Mononoke',
        'my_status' => 'Completed',
        'my_watched_episodes' => '1',
        'series_episodes' => '1',
        'my_score' => '10',
        'my_finish_date' => '2020-01-02',
    ]);
});

test('the day is the viewer\'s own', function () {
    seedExportAnimeMap();
    $user = User::factory()->create(['preferences' => ['timezone' => 'Pacific/Auckland']]);
    $film = MediaItem::factory()->movie()->create(['metadata' => ['imdbId' => 'tt0119698']]);
    // Evening in UTC is the next morning in Auckland.
    app(MarkWatched::class)->execute($user, $film, WatchedAt::at(CarbonImmutable::parse('2020-01-02 20:00')));

    expect(malDownload($user, MalAnimeExport::CODE)['entries'][164]['my_finish_date'])->toBe('2020-01-03');
});

test('a manga list carries chapters as the library counts them', function () {
    enableMangaModule();
    $user = User::factory()->create();
    $reading = MediaItem::factory()->manga()->create(['title' => 'One Punch-Man', 'provider_ref' => '44347']);
    $read = MediaItem::factory()->manga()->create(['title' => 'Berserk', 'provider_ref' => '2', 'metadata' => ['chapters' => 364]]);
    $dropped = MediaItem::factory()->manga()->create(['title' => 'Vagabond', 'provider_ref' => '656']);
    $wanted = MediaItem::factory()->manga()->create(['title' => 'Monster', 'provider_ref' => '1']);

    app(SetMediaStatus::class)->execute($user, $reading, MediaUserStatus::InProgress);
    app(LogProgress::class)->execute($user, $reading, null, 120);
    app(RateItem::class)->execute($user, $reading, 8);
    app(MarkWatched::class)->execute($user, $read, WatchedAt::at(CarbonImmutable::parse('2025-05-05 20:00')));
    app(DropAttempt::class)->execute($user, $dropped, WatchedAt::at(CarbonImmutable::parse('2025-06-06 20:00')), 40);
    app(AddToList::class)->execute($user, $user->watchlist(), ListTarget::item($wanted->id));

    ['info' => $info, 'entries' => $entries] = malDownload($user, MalMangaExport::CODE);

    expect($info)->toMatchArray(['user_export_type' => '2', 'user_total_manga' => '4', 'user_total_reading' => '1', 'user_total_plantoread' => '1'])
        ->and($entries[44347])->toMatchArray(['my_status' => 'Reading', 'my_read_chapters' => '120', 'my_score' => '8', 'my_rereading' => 'NO'])
        ->and($entries[2])->toMatchArray(['my_status' => 'Completed', 'my_read_chapters' => '364', 'manga_chapters' => '364', 'my_finish_date' => '2025-05-05'])
        ->and($entries[656])->toMatchArray(['my_status' => 'Dropped', 'my_read_chapters' => '40'])
        ->and($entries[1])->toMatchArray(['my_status' => 'Plan to Read', 'my_read_chapters' => '0']);
});

test('a manga read twice, or logged on the way to its end, is read to its end once', function () {
    enableMangaModule();
    $user = User::factory()->create();
    $reread = MediaItem::factory()->manga()->create(['provider_ref' => '2', 'metadata' => ['chapters' => 364]]);
    $logged = MediaItem::factory()->manga()->create(['provider_ref' => '656', 'metadata' => []]);

    app(MarkWatched::class)->execute($user, $reread, WatchedAt::at(CarbonImmutable::parse('2024-05-05 20:00')));
    app(MarkWatched::class)->execute($user, $reread, WatchedAt::at(CarbonImmutable::parse('2025-05-05 20:00')));
    app(SetMediaStatus::class)->execute($user, $logged, MediaUserStatus::InProgress);
    app(LogProgress::class)->execute($user, $logged, null, 120);
    app(MarkWatched::class)->execute($user, $logged, WatchedAt::at(CarbonImmutable::parse('2025-06-06 20:00')));

    $entries = malDownload($user, MalMangaExport::CODE)['entries'];

    // Without a count of its own, the reading that finished says how far it went.
    expect($entries[2])->toMatchArray(['my_status' => 'Completed', 'my_read_chapters' => '364', 'my_start_date' => '2024-05-05', 'my_finish_date' => '2025-05-05'])
        ->and($entries[656])->toMatchArray(['my_status' => 'Completed', 'my_read_chapters' => '120']);
});

test('without the anime id map there is no anime list to download', function () {
    Storage::fake('local');
    // The map's host is out of reach and no copy was kept.
    Http::fake([config()->string('tracker.imports.anime_ids_url').'/*' => Http::response('', 503)]);

    $this->actingAs(User::factory()->create())->get(route('settings.export.download', MalAnimeExport::CODE))->assertServiceUnavailable();
});

test('the manga list is offered only with the manga module on', function () {
    $this->actingAs(User::factory()->create())->get(route('settings.export.download', MalMangaExport::CODE))->assertNotFound();
});

test('a title cannot close the file\'s markup', function () {
    seedExportAnimeMap();
    $user = User::factory()->create();
    $film = MediaItem::factory()->movie()->create(['title' => 'A <b> & ]]> film', 'metadata' => ['imdbId' => 'tt0119698']]);
    app(RateItem::class)->execute($user, $film, 6);

    expect(malDownload($user, MalAnimeExport::CODE)['entries'][164]['series_title'])->toBe('A <b> & ]]> film');
});

test('the files read back through the MyAnimeList import into the same viewings, ratings and plans', function () {
    enableMangaModule();
    seedExportAnimeMap();
    $viewer = User::factory()->create();
    ['frieren' => $frieren, 'film' => $film] = seedAnimeViewer($viewer);
    $manga = MediaItem::factory()->manga()->create(['provider_ref' => '44347']);
    app(SetMediaStatus::class)->execute($viewer, $manga, MediaUserStatus::InProgress);
    app(LogProgress::class)->execute($viewer, $manga, null, 120);
    app(RateItem::class)->execute($viewer, $manga, 8);

    Http::fake([
        '*/find/100*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => 9100]]]),
        '*/find/400*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => 9400]]]),
        '*/find/500*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => 9500]]]),
        '*/find/tt0119698*' => Http::response(['movie_results' => [['id' => 128]], 'tv_results' => []]),
        'api.themoviedb.org/*' => Http::response(['status_message' => 'Not found'], 404),
    ]);

    $reader = User::factory()->create();

    foreach ([MalAnimeExport::CODE, MalMangaExport::CODE] as $format) {
        Storage::disk('local')->put("imports/{$format}.xml", test()->actingAs($viewer)->get(route('settings.export.download', $format))->streamedContent());
        expect(runImportFile($reader, MalXmlSource::CODE, "imports/{$format}.xml")->status)->toBe(TaskRunStatus::Success);
    }

    $episodes = $reader->plays()->whereHas('mediaItem', fn (Builder $query) => $query->where('type', MediaKind::Episode->value))
        ->with('mediaItem')->get()->map(fn (Play $play): ?string => $play->mediaItem?->provider_ref)->unique()->sort()->values()->all();

    // Every episode watched, but the shared season 3 and the show that is no anime: those never left.
    expect($episodes)->toBe(['9100:1:1', '9100:1:2', '9100:2:1', '9100:2:2', '9100:2:3', '9500:1:1'])
        ->and($reader->plays()->where('media_item_id', $film->id)->sole()->watched_at->toDateString())->toBe('2020-01-02')
        ->and($reader->ratings()->where('media_item_id', $film->id)->value('rating'))->toBe(10)
        // A season's rating comes back on the show: the import knows no part of a show to put it on.
        ->and($reader->ratings()->where('media_item_id', $frieren->id)->value('rating'))->toBe(9)
        ->and($reader->mediaStates()->where('media_item_id', $manga->id)->sole()->progress)->toBe(120)
        ->and($reader->ratings()->where('media_item_id', $manga->id)->value('rating'))->toBe(8);
});
