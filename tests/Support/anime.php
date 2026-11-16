<?php

use App\Integrations\Anime\AnimeIdMap;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The pinned anime id map, already verified on disk — the test copy stands
 * in for the real revision.
 */
function seedAnimeIdMap(): void
{
    Storage::fake('local');
    Storage::disk('local')->put(
        'anime-ids/'.AnimeIdMap::REVISION.'.json',
        (string) file_get_contents(fixture('transfer/anime/anime_ids.json')),
    );
}

/**
 * Attack on Titan (TVDB 267440) is TMDB 1429, with two episodes of season 1
 * known locally; Princess Mononoke (tt0119698) is TMDB 128. Anything else
 * TMDB is asked about is unknown.
 *
 * @return array{show: MediaItem, film: MediaItem}
 */
function animeCatalogue(): array
{
    return [
        'show' => showWithRefs('1429', [1 => ['2013-04-07', '2013-04-14']]),
        'film' => MediaItem::factory()->movie()->create(['provider_ref' => '128']),
    ];
}

/**
 * @return array<string, mixed>
 */
function tmdbAnimeFakes(): array
{
    return [
        '*/find/267440*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => 1429]]]),
        '*/find/tt0119698*' => Http::response(['movie_results' => [['id' => 128]], 'tv_results' => []]),
        'api.themoviedb.org/*' => Http::response(['status_message' => 'Not found'], 404),
    ];
}
