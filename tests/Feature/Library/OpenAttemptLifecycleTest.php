<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;

/**
 * The OPEN attempt — the `media_user_states` row — carries two things at
 * once: the manual status (on hold) and the sessions logged so far. Clearing
 * the status therefore has to RESUME the attempt, keeping the sessions,
 * while throwing the attempt away is a separate, explicit request
 * (`discard_progress`). Collapsing the two would make hours of play vanish
 * behind an innocent "remove status".
 */
beforeEach(function () {
    enableGamesModule();
    enableBooksModule();
});

test('taking a hold off a game resumes the attempt and keeps the logged sessions', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 120])
        ->assertSuccessful();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => 'on_hold'])
        ->assertSuccessful();

    // Pausing paused the playing, not the two hours already played.
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => null])
        ->assertSuccessful();

    $state = MediaUserState::query()->where('media_item_id', $game->id)->firstOrFail();

    expect($state->progress)->toBe(120)
        ->and($state->status)->toBe(MediaUserStatus::InProgress);
});

test('taking a hold off a book keeps the page position too', function () {
    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'book', 'ref' => $book->provider_ref]), ['set' => 250])
        ->assertSuccessful();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'book', 'ref' => $book->provider_ref]), ['status' => 'on_hold'])
        ->assertSuccessful();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'book', 'ref' => $book->provider_ref]), ['status' => null])
        ->assertSuccessful();

    $state = MediaUserState::query()->where('media_item_id', $book->id)->firstOrFail();

    expect($state->progress)->toBe(250)
        ->and($state->status)->toBe(MediaUserStatus::InProgress);
});

test('a hold with nothing logged leaves no row behind when it is taken off', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['status' => 'on_hold'])
        ->assertSuccessful();

    expect(MediaUserState::query()->count())->toBe(1);

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['status' => null])
        ->assertSuccessful();

    // Nothing to resume — the row held the status and nothing else.
    expect(MediaUserState::query()->count())->toBe(0);
});

test('discarding the attempt throws the status and the logged sessions away together', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 120])
        ->assertSuccessful();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => null, 'discard_progress' => true])
        ->assertSuccessful();

    expect(MediaUserState::query()->count())->toBe(0);
});

test('discarding works from a hold as well, not only from an open session', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 45])
        ->assertSuccessful();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => 'on_hold'])
        ->assertSuccessful();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => null, 'discard_progress' => true])
        ->assertSuccessful();

    expect(MediaUserState::query()->count())->toBe(0)
        ->and(Play::query()->count())->toBe(0);
});

test('un-dropping removes the dropped entry and resurrects nothing', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 300])
        ->assertSuccessful();

    // Dropping freezes the sessions into the entry and closes the state row.
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => 'dropped'])
        ->assertSuccessful();

    expect(MediaUserState::query()->count())->toBe(0)
        ->and((int) Play::query()->firstOrFail()->amount)->toBe(300);

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => null])
        ->assertSuccessful();

    // The drop is gone; the hours it froze went with it, not back into an
    // open attempt — history is only ever rewritten explicitly.
    expect(Play::query()->count())->toBe(0)
        ->and(MediaUserState::query()->count())->toBe(0);
});

test('discard_progress is ignored while dropping — the entry still freezes the hours', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => 'dropped', 'amount' => 600, 'discard_progress' => true])
        ->assertSuccessful();

    $entry = Play::query()->firstOrFail();

    expect($entry->outcome)->toBe(PlayOutcome::Dropped)
        ->and((int) $entry->amount)->toBe(600);
});

test('discard_progress rejects a non-boolean', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['status' => null, 'discard_progress' => 'maybe'])
        ->assertJsonValidationErrors('discard_progress');
});

test('correcting the counter to zero keeps the attempt open instead of erroring', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['add' => 90])
        ->assertSuccessful();

    $this->actingAs($user)
        ->putJson(route('ui.media.progress.update', ['type' => 'game', 'ref' => $game->provider_ref]), ['set' => 0])
        ->assertSuccessful();

    $state = MediaUserState::query()->where('media_item_id', $game->id)->firstOrFail();

    expect($state->progress)->toBe(0);
});
