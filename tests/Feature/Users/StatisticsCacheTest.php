<?php

use App\Actions\Library\ClearUserData;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\RemoveRating;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Users\UpdatePreferences;
use App\Enums\MediaUserStatus;
use App\Events\Media\TitleRefreshed;
use App\Library\LibraryProjector;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use App\Users\Statistics\StatisticsCache;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function cacheUser(): User
{
    return User::factory()->create(['preferences' => ['timezone' => 'Europe/Warsaw']]);
}

/** A film watched through the action, which announces it. */
function watchFilm(User $user, int $runtime): MediaItem
{
    $film = MediaItem::factory()->movie()->create(['runtime' => $runtime]);
    app(MarkWatched::class)->execute($user, $film, WatchedAt::at(CarbonImmutable::parse('2026-03-01 20:00')));

    return $film;
}

/**
 * A change nothing announces: the film's length set straight in the
 * catalog. The statistics count it only once something they listen to
 * moves their key.
 */
function lengthenQuietly(MediaItem $film, int $runtime): void
{
    MediaItem::query()->whereKey($film->id)->update(['runtime' => $runtime]);
}

function statisticsMinutes(User $user): int
{
    $minutes = 0;

    test()->actingAs($user)
        ->get(route('statistics', ['period' => 2026]))
        ->assertInertia(function (Assert $page) use (&$minutes): void {
            $minutes = $page->toArray()['props']['page']['summary']['time']['total'];
        });

    return $minutes;
}

test('a view seen before is read from what was kept, until the library changes', function () {
    $user = cacheUser();
    $film = watchFilm($user, 100);
    expect(statisticsMinutes($user))->toBe(100);

    lengthenQuietly($film, 160);
    expect(statisticsMinutes($user))->toBe(100);

    watchFilm($user, 30);
    expect(statisticsMinutes($user))->toBe(190);
});

test('every change the statistics count is worked out again', function (Closure $change) {
    $user = cacheUser();
    $film = watchFilm($user, 100);
    expect(statisticsMinutes($user))->toBe(100);

    lengthenQuietly($film, 160);
    $change($user, $film);

    expect(statisticsMinutes($user))->not->toBe(100);
})->with([
    'a rating' => [fn (User $user, MediaItem $film) => app(RateItem::class)->execute($user, $film, 8)],
    'a rating taken back' => [function (User $user, MediaItem $film): void {
        app(RateItem::class)->execute($user, $film, 8);
        app(RemoveRating::class)->execute($user, $film);
    }],
    'a status' => [fn (User $user, MediaItem $film) => app(SetMediaStatus::class)->execute($user, $film, MediaUserStatus::OnHold)],
    'the library wiped' => [fn (User $user) => app(ClearUserData::class)->execute($user)],
    'a history written quietly, then projected' => [function (User $user): void {
        Play::factory()->for($user)->for(MediaItem::factory()->movie()->create(['runtime' => 5]), 'mediaItem')->create(['watched_at' => '2026-03-02 20:00:00']);
        app(LibraryProjector::class)->rebuild($user);
    }],
    'a refresh of a title of theirs' => [fn (User $user, MediaItem $film) => event(new TitleRefreshed($film->id))],
    'a changed setting' => [fn (User $user) => app(UpdatePreferences::class)->execute($user, ['reading_pace_pages' => 40])],
]);

test('a refresh of a title someone else has, or another user\'s write, leaves the kept statistics alone', function () {
    $alex = cacheUser();
    $sam = cacheUser();
    $film = watchFilm($alex, 100);
    $samsFilm = watchFilm($sam, 40);

    expect(statisticsMinutes($alex))->toBe(100)
        ->and(statisticsMinutes($sam))->toBe(40);

    lengthenQuietly($film, 160);
    event(new TitleRefreshed($samsFilm->id));
    watchFilm($sam, 30);

    expect(statisticsMinutes($alex))->toBe(100)
        ->and(statisticsMinutes($sam))->toBe(70);
});

test('the database store, which cannot count a key it never held, still moves a version from nothing', function () {
    config(['cache.default' => 'database']);
    $user = cacheUser();
    $film = watchFilm($user, 100);
    expect(statisticsMinutes($user))->toBe(100);

    lengthenQuietly($film, 160);
    app(StatisticsCache::class)->forgetLibrary($user->id);
    expect(statisticsMinutes($user))->toBe(160);

    lengthenQuietly($film, 175);
    app(StatisticsCache::class)->forgetLibrary($user->id);
    expect(statisticsMinutes($user))->toBe(175);
});
