<?php

use App\Actions\Media\BuildWatchStats;
use App\Actions\Media\ResolveNowPlaying;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;

beforeEach(function () {
    enableBooksModule();
});

test('saving a page position sets the counter and flips the status to reading', function () {
    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create();

    // Books log a position ("I'm on page 250"), not an increment — the UI
    // sends absolute sets plus one `add` when a session bumps the counter.
    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'book', 'ref' => $book->provider_ref]), ['add' => 102])
        ->assertSuccessful();

    $state = MediaUserState::query()->firstOrFail();

    expect($state->progress)->toBe(102)
        ->and($state->status)->toBe(MediaUserStatus::InProgress);
});

test('reaching the last page finishes the book, freezing the page count and clearing the state', function () {
    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create(); // 320 pages

    // Setting the position to (or beyond, clamped) the book length means the
    // reader finished — the counter freezes into a completed play and the
    // in-progress row is gone, so no "reading" state stays parked at the maximum.
    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'book', 'ref' => $book->provider_ref]), ['set' => 99999])
        ->assertSuccessful();

    $play = Play::query()->firstOrFail();

    expect($play->outcome)->toBe(PlayOutcome::Completed)
        ->and($play->amount)->toBe(320)
        ->and(MediaUserState::query()->count())->toBe(0);
});

test('a session bumping the page count onto the last page finishes the book too', function () {
    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create(); // 320 pages

    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $book->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 300,
    ]);

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'book', 'ref' => $book->provider_ref]), ['add' => 20])
        ->assertSuccessful();

    expect(Play::query()->firstOrFail()->amount)->toBe(320)
        ->and(MediaUserState::query()->count())->toBe(0);
});

test('a page position below the book length stays an in-progress read', function () {
    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create(); // 320 pages

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'book', 'ref' => $book->provider_ref]), ['set' => 319])
        ->assertSuccessful();

    $state = MediaUserState::query()->firstOrFail();

    expect($state->progress)->toBe(319)
        ->and($state->status)->toBe(MediaUserStatus::InProgress)
        ->and(Play::query()->count())->toBe(0);
});

test('finishing a book freezes the page position into the play and clears the state', function () {
    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create();

    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $book->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 250,
    ]);

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'book', 'ref' => $book->provider_ref]), ['watched_at' => 'now'])
        ->assertSuccessful();

    expect(Play::query()->firstOrFail()->amount)->toBe(250)
        ->and(MediaUserState::query()->count())->toBe(0);
});

test('currently reading entries carry the page count for bars and input caps', function () {
    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create();

    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $book->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 102,
    ]);
    projectEveryLibrary();

    $entries = app(ResolveNowPlaying::class)->execute($user);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->progress)->toBe(102)
        ->and($entries[0]->media->extra['pages'])->toBe(320);
});

test('book statistics count every attempt and pages separately from watch time', function () {
    $user = User::factory()->create();

    // A finished book: its page total lands in the book tile only.
    $finished = MediaItem::factory()->book()->create();
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $finished->id, 'watched_at' => now(), 'amount' => 320]);

    // A dropped one: attempt counting — it is a book and a
    // history entry, and its frozen pages count.
    $abandoned = MediaItem::factory()->book()->create();
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $abandoned->id,
        'watched_at' => now(),
        'outcome' => PlayOutcome::Dropped,
        'amount' => 120,
    ]);

    // Still reading: an open attempt counts as a title (no history entry
    // yet, so no play) and its position feeds the page tally.
    $reading = MediaItem::factory()->book()->create();
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $reading->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 102,
    ]);

    // A watched movie keeps its runtime minutes in its own bucket — and a
    // dropped movie stays out of the movie tallies (completed-only there).
    $movie = MediaItem::factory()->movie()->create(['runtime' => 90]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'watched_at' => now()]);
    $droppedMovie = MediaItem::factory()->movie()->create(['runtime' => 120]);
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $droppedMovie->id,
        'watched_at' => now(),
        'outcome' => PlayOutcome::Dropped,
    ]);
    projectEveryLibrary();

    $stats = app(BuildWatchStats::class)->execute($user);

    expect($stats['book']->minutes)->toBe(320 + 120 + 102)
        ->and($stats['book']->watched)->toBe(3)
        ->and($stats['book']->plays)->toBe(2)
        ->and($stats['movie']->minutes)->toBe(90)
        ->and($stats['movie']->watched)->toBe(1)
        ->and($stats['movie']->plays)->toBe(1)
        // Pages read as time at the default pace; a film's minutes are no estimate.
        ->and($stats['book']->estimatedMinutes)->toBe((int) round((320 + 120 + 102) * 60 / jsonFixture('preferences/defaults')['reading_pace_pages']))
        ->and($stats['movie']->estimatedMinutes)->toBeNull();
});
