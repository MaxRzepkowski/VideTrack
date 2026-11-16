<?php

use App\Catalog\Providers\Mal\MalProvider;
use App\Catalog\Providers\Mal\MalRankings;
use App\Catalog\Providers\Mal\MangaRankingType;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The top of a MAL ranking in one answer: $manga manga, every fifth a light
 * novel and every seventh adult, which the listing leaves out.
 */
function malRankingTop(int $entries): array
{
    $data = [];

    for ($rank = 1; $rank <= $entries; $rank++) {
        $data[] = ['node' => [
            'id' => 1000 + $rank,
            'title' => "Manga {$rank}",
            'main_picture' => ['large' => "https://cdn.myanimelist.net/images/manga/{$rank}l.jpg"],
            'media_type' => $rank % 5 === 0 ? 'light_novel' : 'manga',
            'nsfw' => $rank % 7 === 0 ? 'black' : 'white',
        ], 'ranking' => ['rank' => $rank]];
    }

    return ['data' => $data, 'paging' => ['next' => 'https://api.myanimelist.net/v2/manga/ranking?offset=480']];
}

/** How many of malRankingTop()'s entries the listing keeps. */
function keptOfRankingTop(int $entries): int
{
    return count(array_filter(range(1, $entries), fn (int $rank): bool => $rank % 5 !== 0 && $rank % 7 !== 0));
}

test('the rankings are one select of MAL ranking types, with no resting value', function () {
    enableMangaModule();

    [$filter] = app(MalProvider::class)->discoverFilters('manga', 'en', 'US');

    expect($filter->key)->toBe(MalRankings::FILTER)
        ->and($filter->default)->toBeNull()
        ->and(array_map(fn ($option) => $option->value, $filter->options))
        ->toBe(array_map(fn (MangaRankingType $ranking) => $ranking->value, MangaRankingType::cases()));
});

test('a ranking is its top in one answer, narrowed here, so every page is full and the total is true', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/ranking*' => Http::response(malRankingTop(MalRankings::RANKING_DEPTH))]);

    $kept = keptOfRankingTop(MalRankings::RANKING_DEPTH);
    $first = app(MalProvider::class)->discoverFiltered('manga', [MalRankings::FILTER => 'manhwa'], 'en', 'US', 1);
    $second = app(MalProvider::class)->discoverFiltered('manga', [MalRankings::FILTER => 'manhwa'], 'en', 'US', 2);

    expect($first->total)->toBe($kept)
        ->and($first->totalPages)->toBe((int) ceil($kept / MalRankings::PAGE_SIZE))
        ->and($first->items)->toHaveCount(MalRankings::PAGE_SIZE)
        ->and($first->items[0]->ref)->toBe('1001')
        // The page holds the ranks kept, not the next 24 ranks: it starts at the 25th kept one.
        ->and($second->items[0]->ref)->toBe((string) (1000 + array_values(array_filter(range(1, MalRankings::RANKING_DEPTH), fn (int $rank): bool => $rank % 5 !== 0 && $rank % 7 !== 0))[MalRankings::PAGE_SIZE]));

    // One answer serves every page.
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['ranking_type'] ?? null) === 'manhwa'
            && ($query['limit'] ?? null) === (string) MalRankings::RANKING_DEPTH
            && ($query['nsfw'] ?? null) === 'true';
    });
});

test('a ranking shorter than the depth ends where it does, and a ranking not offered names nothing', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/ranking*' => Http::response(malRankingTop(30))]);

    $short = app(MalProvider::class)->discoverFiltered('manga', [MalRankings::FILTER => 'oneshots'], 'en', 'US', 5);
    $unknown = app(MalProvider::class)->discoverFiltered('manga', [MalRankings::FILTER => 'novels; drop'], 'en', 'US');

    expect($short->total)->toBe(keptOfRankingTop(30))
        ->and($short->totalPages)->toBe(1)
        ->and($short->page)->toBe(1)
        ->and($unknown->items)->toBe([])
        ->and($unknown->total)->toBe(0);
    Http::assertSentCount(1);
});

test('the discover page lists a chosen MAL ranking in its filtered grid', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/v2/manga/ranking*' => Http::response(malRankingTop(MalRankings::RANKING_DEPTH))]);

    $this->actingAs(User::factory()->create())
        ->get(route('discover', ['type' => 'manga', 'f' => [MalRankings::FILTER => 'favorite']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.0.key', MalRankings::FILTER)
            ->where('filteredItems.0.ref', '1001')
            ->where('filteredPagination.currentPage', 1)
            ->where('filteredPagination.total', keptOfRankingTop(MalRankings::RANKING_DEPTH))
        );
});
