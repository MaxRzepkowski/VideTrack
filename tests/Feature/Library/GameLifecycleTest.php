<?php

use App\Actions\Media\BuildWatchStats;
use App\Catalog\MediaItemSummaries;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Library\LibraryStates;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;

beforeEach(function () {
    enableGamesModule();
});

test('a session accumulates progress and flips the status to in progress', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 90])
        ->assertSuccessful();
    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 30])
        ->assertSuccessful();

    $state = MediaUserState::query()->where('user_id', $user->id)->where('media_item_id', $game->id)->firstOrFail();

    expect($state->progress)->toBe(120)
        ->and($state->status)->toBe(MediaUserStatus::InProgress);
});

test('a session on an on-hold game resumes playing, an absolute edit does not', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $game->id,
        'status' => MediaUserStatus::OnHold,
        'progress' => 60,
    ]);

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['set' => 45])
        ->assertSuccessful();

    expect(MediaUserState::query()->firstOrFail()->status)->toBe(MediaUserStatus::OnHold)
        ->and(MediaUserState::query()->firstOrFail()->progress)->toBe(45);

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 15])
        ->assertSuccessful();

    expect(MediaUserState::query()->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and(MediaUserState::query()->firstOrFail()->progress)->toBe(60);
});

test('progress is rejected for types without a progress unit', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['add' => 90])
        ->assertJsonValidationErrors('type');
});

test('completing a game freezes the session progress into the play and clears the state', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $game->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 300,
    ]);

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'game', 'ref' => $game->provider_ref]), ['watched_at' => 'now'])
        ->assertSuccessful();

    $play = Play::query()->firstOrFail();

    expect($play->amount)->toBe(300)
        ->and(MediaUserState::query()->count())->toBe(0);

    // The frozen playtime stays visible on the game page afterwards.
    $state = app(LibraryStates::class)->resolve($user, [MediaItemSummaries::summary($game)]);

    expect($state["game:{$game->provider_ref}"]->playedAmount)->toBe(300);
});

test('dropping a game freezes explicit hours into the entry; movies never carry them', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    // Straight from the dropped tab — no session round-trip needed first.
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => 'dropped', 'amount' => 750])
        ->assertSuccessful();

    $entry = Play::query()->where('media_item_id', $game->id)->firstOrFail();

    expect($entry->outcome->value)->toBe('dropped')
        ->and((int) $entry->amount)->toBe(750);

    // Reopening in place merges the frozen hours when no total is given.
    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'game', 'ref' => $game->provider_ref]), ['watched_at' => 'now'])
        ->assertSuccessful();

    $merged = Play::query()->where('media_item_id', $game->id)->firstOrFail();

    expect($merged->outcome->value)->toBe('completed')
        ->and((int) $merged->amount)->toBe(750);

    $movie = MediaItem::factory()->movie()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['status' => 'dropped', 'amount' => 90])
        ->assertSuccessful();

    expect(Play::query()->where('media_item_id', $movie->id)->firstOrFail()->amount)->toBeNull();
});

test('completing with "overwrite" starts the counter fresh instead of merging', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => 'dropped', 'amount' => 600])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'game', 'ref' => $game->provider_ref]), ['watched_at' => 'now', 'merge_hours' => false])
        ->assertSuccessful();

    $entry = Play::query()->where('media_item_id', $game->id)->firstOrFail();

    expect($entry->outcome->value)->toBe('completed')
        ->and($entry->amount)->toBeNull();
});

test('an explicit total playtime wins over the accumulated sessions', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $game->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 300,
    ]);

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'game', 'ref' => $game->provider_ref]), ['watched_at' => 'now', 'amount' => 2400])
        ->assertSuccessful();

    expect(Play::query()->firstOrFail()->amount)->toBe(2400);
});

test('movie plays never carry an amount', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['watched_at' => 'now', 'amount' => 500])
        ->assertSuccessful();

    expect(Play::query()->firstOrFail()->amount)->toBeNull();
});

test('playing is automatic — the status endpoint does not accept in_progress', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    // There is no manual "playing"; a session sets it (LogProgress), the
    // endpoint only writes on hold / dropped.
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => 'in_progress'])
        ->assertJsonValidationErrors('status');

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 60])
        ->assertSuccessful();

    expect(MediaUserState::query()->firstOrFail()->status)->toBe(MediaUserStatus::InProgress);
});

test('the plays panel lists plays with recorded hours, newest first', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $old = Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $game->id, 'watched_at' => now()->subYear(), 'amount' => 2400]);
    $recent = Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $game->id, 'watched_at' => now(), 'amount' => null]);

    // Another user's play never leaks into the panel.
    Play::factory()->create(['user_id' => User::factory()->create()->id, 'media_item_id' => $game->id, 'watched_at' => now()]);
    projectEveryLibrary();

    $this->actingAs($user)
        ->getJson(route('ui.media.plays.index', ['type' => 'game', 'ref' => $game->provider_ref]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $recent->id)
        ->assertJsonPath('data.0.amount', null)
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.1.amount', 2400);
});

test('a play amount can be corrected but only by its owner', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();
    $play = Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $game->id, 'watched_at' => now(), 'amount' => 2400]);

    $this->actingAs($user)
        ->patchJson(route('ui.plays.update', ['play' => $play->id]), ['amount' => 3000])
        ->assertSuccessful();

    expect($play->refresh()->amount)->toBe(3000);

    $this->actingAs(User::factory()->create())
        ->patchJson(route('ui.plays.update', ['play' => $play->id]), ['amount' => 1])
        ->assertNotFound();

    expect($play->refresh()->amount)->toBe(3000);
});

test('game stats prefer real playtime and count in-progress sessions', function () {
    $user = User::factory()->create();

    // Completed with real hours: 2400 min beats the 5536 min estimate.
    $beaten = MediaItem::factory()->game()->create(['runtime' => 5536]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $beaten->id, 'watched_at' => now(), 'amount' => 2400]);

    // Completed without hours (e.g. imported): counts as a game, adds NO
    // time — the time-to-beat estimate in `runtime` is never real playtime.
    $estimated = MediaItem::factory()->game()->create(['runtime' => 600]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $estimated->id, 'watched_at' => now()]);

    // Still playing: sessions count without any play.
    $playing = MediaItem::factory()->game()->create();
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $playing->id,
        'status' => MediaUserStatus::InProgress,
        'progress' => 120,
    ]);

    // Dropped with frozen hours: the time counts, and since attempts are
    // counted, the dropped attempt makes both tallies too.
    $abandoned = MediaItem::factory()->game()->create(['runtime' => 3000]);
    Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $abandoned->id,
        'watched_at' => now(),
        'outcome' => PlayOutcome::Dropped,
        'amount' => 300,
    ]);
    projectEveryLibrary();

    $stats = app(BuildWatchStats::class)->execute($user);

    // Titles = every attempt, open ones included (beaten, estimated,
    // abandoned + the game still being played); plays = history entries
    // (2 completions + 1 drop — an open session is not an entry yet).
    expect($stats['game']->minutes)->toBe(2400 + 120 + 300)
        ->and($stats['game']->watched)->toBe(4)
        ->and($stats['game']->plays)->toBe(3)
        ->and($stats['game']->titles)->toBe(4);
});
