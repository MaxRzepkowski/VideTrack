<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Media\RefreshTitle;
use App\Catalog\TitleStore;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Game of Thrones as the fixtures have it, season 1 answering whatever
 * `$season1` holds at the moment it is asked.
 *
 * @param  array<string, mixed>  $season1
 */
function fakeShowWithSeason1(array &$season1): void
{
    Http::fake(function (Request $request) use (&$season1) {
        $url = $request->url();

        foreach (providerDictionaryFakes() as $pattern => $response) {
            if (Str::is($pattern, preg_replace('#^https?://#', '', $url))) {
                return $response;
            }
        }

        return match (true) {
            str_contains($url, '/tv/1399/season/0') => Http::response(jsonFixture('tmdb/season0')),
            str_contains($url, '/tv/1399/season/1') => Http::response($season1),
            str_contains($url, '/tv/1399/season/2') => Http::response(jsonFixture('tmdb/season2')),
            str_contains($url, '/tv/1399') => tmdbShowAnswer(seasons: array_replace(tmdbShowSeasons(), [1 => $season1]))($request),
            default => Http::response([], 404),
        };
    });
}

function refreshShow(): MediaItem
{
    $show = MediaItem::query()->where(['type' => 'show', 'provider_ref' => '1399'])->firstOrFail();
    // The provider's answers are cached; the refresh must see the new one.
    Cache::flush();
    app(RefreshTitle::class)->execute($show, 'en');

    return $show;
}

test('an episode the provider dropped goes when nobody holds anything on it', function () {
    $season1 = jsonFixture('tmdb/season');
    fakeShowWithSeason1($season1);
    app(TitleStore::class)->persist('show', '1399', 'en');

    $season1['episodes'] = array_slice($season1['episodes'], 0, 1);
    refreshShow();

    expect(MediaItem::query()->where('provider_ref', '1399:1:2')->exists())->toBeFalse()
        ->and(MediaItem::query()->where('provider_ref', '1399:1:1')->exists())->toBeTrue();
});

test('an episode the provider dropped stays when somebody watched it', function () {
    $season1 = jsonFixture('tmdb/season');
    fakeShowWithSeason1($season1);
    $episode = app(TitleStore::class)->persist('episode', '1399:1:2', 'en');
    app(MarkWatched::class)->execute(User::factory()->create(), $episode, WatchedAt::at(CarbonImmutable::now()));

    $season1['episodes'] = array_slice($season1['episodes'], 0, 1);
    refreshShow();

    expect($episode->fresh())->not->toBeNull();
});

test('a season answered with no episodes keeps the ones stored', function () {
    $season1 = jsonFixture('tmdb/season');
    fakeShowWithSeason1($season1);
    app(TitleStore::class)->persist('show', '1399', 'en');

    $season1['episodes'] = [];
    refreshShow();

    expect(MediaItem::query()->where('provider_ref', 'like', '1399:1:%')->count())->toBe(2);
});
