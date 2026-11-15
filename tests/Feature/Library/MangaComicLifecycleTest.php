<?php

use App\Actions\Library\SyncListMembership;
use App\Actions\Media\BuildWatchStats;
use App\Actions\Media\ResolveNowPlaying;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Library\Data\PlayingEntry;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use App\Tracking\Surfaces\CalendarDays;

beforeEach(function () {
    enableMangaModule();
    enableComicsModule();
});

test('saving a chapter position sets the counter and flips the status to reading', function () {
    $user = User::factory()->create();
    $manga = MediaItem::factory()->manga()->create();

    // Manga log a position ("I'm on chapter 42") exactly like book pages —
    // the UI sends absolute sets.
    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'manga', 'ref' => $manga->provider_ref]), ['set' => 42])
        ->assertSuccessful();

    $state = MediaUserState::query()->firstOrFail();

    expect($state->progress)->toBe(42)
        ->and($state->status)->toBe(MediaUserStatus::InProgress);
});

test('finishing a manga freezes the chapter position into the play and clears the state', function () {
    $user = User::factory()->create();
    $manga = MediaItem::factory()->manga()->create();

    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $manga->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 120,
    ]);

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'manga', 'ref' => $manga->provider_ref]), ['watched_at' => 'now'])
        ->assertSuccessful();

    expect(Play::query()->firstOrFail()->amount)->toBe(120)
        ->and(MediaUserState::query()->count())->toBe(0);
});

test('reaching the last chapter finishes the manga, freezing the count and clearing the state', function () {
    $user = User::factory()->create();
    $manga = MediaItem::factory()->manga()->create(); // 162 chapters

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'manga', 'ref' => $manga->provider_ref]), ['set' => 162])
        ->assertSuccessful();

    $play = Play::query()->firstOrFail();

    expect($play->outcome)->toBe(PlayOutcome::Completed)
        ->and($play->amount)->toBe(162)
        ->and(MediaUserState::query()->count())->toBe(0);
});

test('an issue position on a comic sets the counter and flips the status to reading', function () {
    $user = User::factory()->create();
    $comic = MediaItem::factory()->comic()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'comic', 'ref' => $comic->provider_ref]), ['set' => 12])
        ->assertSuccessful();

    $state = MediaUserState::query()->firstOrFail();

    expect($state->progress)->toBe(12)
        ->and($state->status)->toBe(MediaUserStatus::InProgress);
});

test('currently reading entries carry the chapter and issue totals for bars and caps', function () {
    $user = User::factory()->create();
    $manga = MediaItem::factory()->manga()->create();
    $comic = MediaItem::factory()->comic()->create();

    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $manga->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 42,
    ]);
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $comic->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 12,
    ]);
    projectEveryLibrary();

    $entries = collect(app(ResolveNowPlaying::class)->execute($user))->keyBy(fn (PlayingEntry $entry) => $entry->media->type);

    expect($entries)->toHaveCount(2)
        ->and($entries['manga']->progress)->toBe(42)
        ->and($entries['manga']->media->extra['chapters'])->toBe(162)
        ->and($entries['comic']->progress)->toBe(12)
        ->and($entries['comic']->media->extra['issues'])->toBe(75);
});

test('a backlogged manga premieres in the calendar; comics have no dates to show', function () {
    $user = User::factory()->create();

    // Manga carry MAL start dates — an unreleased one on the backlog is a
    // calendar entry through the same fallback path as books and games
    // without platform data.
    $manga = MediaItem::factory()->manga()->create(['release_date' => today()->addDays(7)->toDateString()]);
    app(SyncListMembership::class)->execute($user, $manga, [$user->watchlist()->id]);

    // ComicVine serves no release dates on volumes — a tracked comic never
    // feeds the calendar (and never crashes it).
    $comic = MediaItem::factory()->comic()->create();
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $comic->id, 'watched_at' => now()]);
    projectEveryLibrary();

    $days = app(CalendarDays::class)->nearest($user);

    expect($days)->toHaveCount(1)
        ->and($days[0]->date)->toBe(today()->addDays(7)->toDateString())
        ->and($days[0]->entries)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->type)->toBe('manga');
});

test('manga and comic statistics count attempts in their own buckets', function () {
    $user = User::factory()->create();

    // A finished manga: its chapter total lands in the manga tile only.
    $finished = MediaItem::factory()->manga()->create();
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $finished->id, 'watched_at' => now(), 'amount' => 162]);

    // A dropped comic: attempt counting — it is a title and a history
    // entry, and its frozen issues count.
    $abandoned = MediaItem::factory()->comic()->create();
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $abandoned->id,
        'watched_at' => now(),
        'outcome' => PlayOutcome::Dropped,
        'amount' => 30,
    ]);

    // Still reading: an open attempt counts as a title (no history entry
    // yet, so no play) and its position feeds the chapter tally.
    $reading = MediaItem::factory()->manga()->create();
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $reading->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 42,
    ]);
    projectEveryLibrary();

    $stats = app(BuildWatchStats::class)->execute($user);

    expect($stats['manga']->minutes)->toBe(162 + 42)
        ->and($stats['manga']->watched)->toBe(2)
        ->and($stats['manga']->plays)->toBe(1)
        ->and($stats['manga']->titles)->toBe(2)
        ->and($stats['comic']->minutes)->toBe(30)
        ->and($stats['comic']->watched)->toBe(1)
        ->and($stats['comic']->plays)->toBe(1);
});
