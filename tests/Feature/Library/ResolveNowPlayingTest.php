<?php

use App\Actions\Media\ResolveNowPlaying;
use App\Enums\MediaUserStatus;
use App\Library\Data\PlayingEntry;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\User;

beforeEach(function () {
    enableGamesModule();
    enableBooksModule();
});

/** @return list<string> the section's titles, in the order the preference puts them */
function nowPlayingTitles(User $user, string $sort): array
{
    $user->preferences = ['up_next_sort' => $sort];
    $user->save();

    return array_map(
        fn (PlayingEntry $entry) => $entry->media->title,
        app(ResolveNowPlaying::class)->execute($user),
    );
}

/**
 * A title the user is in the middle of. `updated_at` is the section's
 * activity clock, so it is written explicitly — rows created in one test
 * otherwise share a timestamp.
 */
function startPlaying(User $user, MediaItem $item, int $progress, string $at): void
{
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $item->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => $progress,
    ]);

    MediaUserState::query()->where('media_item_id', $item->id)->update(['updated_at' => $at]);
}

test('currently playing follows the same order preference as up next', function () {
    $user = User::factory()->create();

    // 70% of its time-to-beat still ahead, played most recently, oldest game.
    $zulu = MediaItem::factory()->game()->create(['title' => 'Zulu', 'runtime' => 1000, 'release_date' => '2020-01-01']);
    startPlaying($user, $zulu, 300, '2026-01-03 12:00:00');

    // Half way through, and the newest release of the three.
    $mike = MediaItem::factory()->game()->create(['title' => 'Mike', 'runtime' => 600, 'release_date' => '2024-01-01']);
    startPlaying($user, $mike, 300, '2026-01-02 12:00:00');

    // No time-to-beat on record: nothing to compare, so it sorts last.
    $alpha = MediaItem::factory()->game()->create(['title' => 'Alpha', 'runtime' => null, 'release_date' => '2022-01-01']);
    startPlaying($user, $alpha, 100, '2026-01-01 12:00:00');
    projectEveryLibrary();

    expect(nowPlayingTitles($user, 'activity'))->toBe(['Zulu', 'Mike', 'Alpha'])
        ->and(nowPlayingTitles($user, 'remaining'))->toBe(['Mike', 'Zulu', 'Alpha'])
        ->and(nowPlayingTitles($user, 'released'))->toBe(['Mike', 'Alpha', 'Zulu'])
        ->and(nowPlayingTitles($user, 'title'))->toBe(['Alpha', 'Mike', 'Zulu']);
});

test('a game played past its time-to-beat is not "nearly finished"', function () {
    $user = User::factory()->create();

    // The endless kind: 300 hours against a 20-hour estimate. It has no
    // meaningful "left to go", so it sits with the estimate-less games at the
    // end instead of leading them.
    $endless = MediaItem::factory()->game()->create(['title' => 'Endless', 'runtime' => 1200]);
    startPlaying($user, $endless, 18_000, '2026-01-02 12:00:00');

    $halfway = MediaItem::factory()->game()->create(['title' => 'Halfway', 'runtime' => 1200]);
    startPlaying($user, $halfway, 600, '2026-01-01 12:00:00');
    projectEveryLibrary();

    expect(nowPlayingTitles($user, 'activity'))->toBe(['Endless', 'Halfway'])
        ->and(nowPlayingTitles($user, 'remaining'))->toBe(['Halfway', 'Endless']);
});

test('reading measures what is left against the page count', function () {
    $user = User::factory()->create();

    $started = MediaItem::factory()->book()->create(['title' => 'Just started', 'metadata' => ['pages' => 100]]);
    startPlaying($user, $started, 10, '2026-01-02 12:00:00');

    $ending = MediaItem::factory()->book()->create(['title' => 'Nearly done', 'metadata' => ['pages' => 320]]);
    startPlaying($user, $ending, 300, '2026-01-01 12:00:00');
    projectEveryLibrary();

    // The freshly opened book leads by activity, the almost-finished one by
    // how little of it is left — 300 of 320 pages against 10 of 100.
    expect(nowPlayingTitles($user, 'activity'))->toBe(['Just started', 'Nearly done'])
        ->and(nowPlayingTitles($user, 'remaining'))->toBe(['Nearly done', 'Just started']);
});
