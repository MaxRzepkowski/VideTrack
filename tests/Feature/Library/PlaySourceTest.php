<?php

use App\Actions\Library\BackfillPlay;
use App\Actions\Library\MarkWatched;
use App\Enums\PlayOutcome;
use App\Library\MutationContext;
use App\Library\MutationSource;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;

// Every history entry keeps where it came in from — the click here, the API,
// an import, a media server — so a doubled or a surprising entry can be traced
// back. The paths other than the app itself are pinned in their own tests.

test('a click in the app is the app\'s, a watched one and a dropped one alike', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'movie', 'ref' => $movie->provider_ref]), ['watched_at' => 'now'])
        ->assertCreated();
    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'show', 'ref' => $show->provider_ref]), ['watched_at' => 'now', 'next_episode' => true])
        ->assertCreated();
    $this->actingAs($user)
        ->putJson(route('ui.media.status.update', ['type' => 'show', 'ref' => $show->provider_ref]), ['status' => 'dropped'])
        ->assertOk();
    // A whole season marked at once: one entry per episode, every one stamped.
    $season = makeShowTree([1 => ['2020-01-01', '2020-01-08']])->children()->firstOrFail();
    $this->actingAs($user)
        ->postJson(route('ui.media.plays.store', ['type' => 'season', 'ref' => $season->provider_ref]), ['watched_at' => 'now'])
        ->assertCreated();

    expect(Play::query()->where('user_id', $user->id)->pluck('source')->unique()->all())->toBe(['ui'])
        ->and(Play::query()->where('user_id', $user->id)->count())->toBe(5);
});

test('correcting an entry leaves where it came from', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();
    $play = Play::factory()->for($user)->for($game)->create(['watched_at' => '2026-01-01 20:00:00', 'amount' => 600, 'source' => 'import:steam']);
    projectLibrary($user);

    $this->actingAs($user)
        ->patchJson(route('ui.plays.update', ['play' => $play->id]), ['amount' => 900])
        ->assertOk();

    expect($play->refresh()->source)->toBe('import:steam')
        ->and($play->amount)->toBe(900);
});

test('an entry from before sources were kept says so', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    Play::factory()->for($user)->for($movie)->create();
    projectLibrary($user);

    $this->actingAs($user)
        ->getJson(route('ui.media.plays.index', ['type' => 'movie', 'ref' => $movie->provider_ref]))
        ->assertOk()
        ->assertJsonPath('data.0.source', null);
});

test('a drop completed later carries the completion\'s source', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MutationContext::class)->run(MutationSource::import('fake'), fn () => app(BackfillPlay::class)->execute($user, $movie, CarbonImmutable::parse('2026-01-01 20:00'), PlayOutcome::Dropped));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-02-01 20:00')));

    $play = Play::query()->where('user_id', $user->id)->sole();

    expect($play->outcome)->toBe(PlayOutcome::Completed)
        ->and($play->source)->toBe('ui');
});
