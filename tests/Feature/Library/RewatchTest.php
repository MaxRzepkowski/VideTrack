<?php

use App\Actions\Library\CancelRewatch;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\StartRewatch;
use App\Actions\Library\UnmarkWatched;
use App\Actions\Media\ResolveUpNext;
use App\Catalog\Data\MediaSummary;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Exceptions\CannotStartRewatch;
use App\Exceptions\NoRewatchToCancel;
use App\Library\Data\UserMediaState;
use App\Library\Data\WatchCycleSummary;
use App\Library\LibraryStates;
use App\Library\WatchCycleResolver;
use App\Library\WatchCycleSummaryResolver;
use App\Library\WatchedAt;
use App\Library\WatchedShows;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use App\Models\WatchCycle;
use Carbon\CarbonImmutable;

/** @return array<string, mixed> */
function rewatchState(User $user, MediaItem $item): UserMediaState
{
    $states = app(LibraryStates::class)->resolve($user, [
        new MediaSummary(type: $item->type, ref: $item->provider_ref, provider: $item->provider, title: $item->title),
    ]);

    return $states["{$item->type}:{$item->provider_ref}"];
}

test('resolves the latest active cycle for each root in one batch', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $firstRoot = MediaItem::factory()->show()->create();
    $secondRoot = MediaItem::factory()->show()->create();
    $implicitRoot = MediaItem::factory()->show()->create();

    WatchCycle::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $firstRoot->id,
        'number' => 2,
        'started_at' => now()->subDay(),
    ]);
    $latest = WatchCycle::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $firstRoot->id,
        'number' => 3,
        'started_at' => now(),
    ]);
    $second = WatchCycle::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $secondRoot->id,
        'number' => 2,
        'started_at' => now(),
    ]);
    WatchCycle::factory()->create([
        'user_id' => $otherUser->id,
        'media_item_id' => $implicitRoot->id,
        'number' => 2,
        'started_at' => now(),
    ]);
    projectEveryLibrary();

    $resolver = app(WatchCycleResolver::class);
    $active = $resolver->activeForRoots($user, collect([$firstRoot, $secondRoot, $implicitRoot]));

    expect($resolver->activeFor($user, $firstRoot)?->is($latest))->toBeTrue()
        ->and($active[$firstRoot->id]?->is($latest))->toBeTrue()
        ->and($active[$secondRoot->id]?->is($second))->toBeTrue()
        ->and($active[$implicitRoot->id])->toBeNull()
        ->and($resolver->activeForRoots($user, collect()))->toBe([]);
});

test('scopes plays to the implicit or explicit cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $episode = $show->children()->firstOrFail()->children()->firstOrFail();
    $cycle = WatchCycle::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $show->id,
        'number' => 2,
        'started_at' => now(),
    ]);

    $implicit = Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $episode->id,
        'watched_at' => now()->subDay(),
    ]);
    $explicit = Play::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $episode->id,
        'watch_cycle_id' => $cycle->id,
        'watched_at' => now(),
    ]);
    projectEveryLibrary();

    expect(Play::query()->inCycle(null)->pluck('id')->all())->toBe([$implicit->id])
        ->and(Play::query()->inCycle($cycle->id)->pluck('id')->all())->toBe([$explicit->id])
        ->and($explicit->watchCycle->is($cycle))->toBeTrue();
});

test('starts cycle two for a played container root and clears its open state', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $episode = $show->children()->firstOrFail()->children()->firstOrFail();

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    MediaUserState::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $show->id,
        'status' => MediaUserStatus::OnHold,
    ]);

    $cycle = app(StartRewatch::class)->execute($user, $show);

    expect($cycle->number)->toBe(2)
        ->and($cycle->media_item_id)->toBe($show->id)
        ->and(MediaUserState::query()->where('media_item_id', $show->id)->exists())->toBeFalse();

    expect(fn () => app(StartRewatch::class)->execute($user, $show))
        ->toThrow(CannotStartRewatch::class);
    expect(WatchCycle::query()->where('media_item_id', $show->id)->count())->toBe(1);
});

test('rejects rewatch for a playable or a container without an active-cycle play', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $emptyShow = makeShowTree([1 => ['2025-01-01']]);

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect(fn () => app(StartRewatch::class)->execute($user, $movie))
        ->toThrow(CannotStartRewatch::class)
        ->and(fn () => app(StartRewatch::class)->execute($user, $emptyShow))
        ->toThrow(CannotStartRewatch::class);
});

test('records an episode in cycle two without touching its baseline play', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $episode = $show->children()->firstOrFail()->children()->firstOrFail();

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    $baseline = Play::query()->firstOrFail();
    $cycle = app(StartRewatch::class)->execute($user, $show);

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    expect(Play::query()->whereKey($baseline->id)->firstOrFail()->watched_at->toDateString())->toBe('2026-01-01')
        ->and(Play::query()->inCycle(null)->where('media_item_id', $episode->id)->count())->toBe(1)
        ->and(Play::query()->inCycle($cycle->id)->where('media_item_id', $episode->id)->count())->toBe(1);
});

test('fills a whole show again inside the active rewatch cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);
    $firstEpisode = $show->children()->firstOrFail()->children()->orderBy('number')->firstOrFail();

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    $cycle = app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $firstEpisode, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    $created = app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-02-02')));

    expect($created)->toBe(1)
        ->and(Play::query()->inCycle(null)->count())->toBe(2)
        ->and(Play::query()->inCycle($cycle->id)->count())->toBe(2)
        ->and(Play::query()->inCycle($cycle->id)->distinct()->count('media_item_id'))->toBe(2);
});

test('does not reopen a dropped episode from an earlier cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $episode = $show->children()->firstOrFail()->children()->firstOrFail();

    app(DropAttempt::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    $baselineDrop = Play::query()->firstOrFail();
    $cycle = app(StartRewatch::class)->execute($user, $show);

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    expect(Play::query()->whereKey($baselineDrop->id)->firstOrFail()->outcome)->toBe(PlayOutcome::Dropped)
        ->and(Play::query()->whereKey($baselineDrop->id)->firstOrFail()->watch_cycle_id)->toBeNull()
        ->and(Play::query()->inCycle($cycle->id)->firstOrFail()->outcome)->toBe(PlayOutcome::Completed)
        ->and(Play::query()->count())->toBe(2);
});

test('unmarks only the active cycle and leaves the cycle itself in place', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    $cycle = app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    $deleted = app(UnmarkWatched::class)->execute($user, $show);

    expect($deleted)->toBe(2)
        ->and(Play::query()->inCycle(null)->count())->toBe(2)
        ->and(Play::query()->inCycle($cycle->id)->count())->toBe(0)
        ->and(WatchCycle::query()->whereKey($cycle->id)->exists())->toBeTrue();
});

test('drops the open rewatch cycle despite a completed baseline cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    $cycle = app(StartRewatch::class)->execute($user, $show);

    app(DropAttempt::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));
    app(DropAttempt::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-02-02')));

    $drop = Play::query()->inCycle($cycle->id)->firstOrFail();

    expect($drop->outcome)->toBe(PlayOutcome::Dropped)
        ->and($drop->media_item_id)->toBe($show->id)
        ->and(Play::query()->inCycle($cycle->id)->count())->toBe(1)
        ->and(Play::query()->inCycle(null)->count())->toBe(1);
});

test('cancels the latest rewatch by deleting the plays it collected', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $episode = $show->children()->firstOrFail()->children()->firstOrFail();

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    $cycleTwo = app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));
    $cycleThree = app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-03-01')));

    // One layer at a time: the run before the cancelled one keeps every play
    // it had, down to the dates.
    expect(app(CancelRewatch::class)->execute($user, $show))->toBe(1);

    expect(WatchCycle::query()->whereKey($cycleThree->id)->exists())->toBeFalse()
        ->and(WatchCycle::query()->whereKey($cycleTwo->id)->exists())->toBeTrue()
        ->and(Play::query()->inCycle($cycleTwo->id)->count())->toBe(1)
        ->and(Play::query()->inCycle(null)->count())->toBe(1)
        ->and(Play::query()->count())->toBe(2);

    app(CancelRewatch::class)->execute($user, $show);

    $remaining = Play::query()->get();

    expect(WatchCycle::query()->where('media_item_id', $show->id)->count())->toBe(0)
        ->and($remaining)->toHaveCount(1)
        ->and($remaining->first()->watch_cycle_id)->toBeNull()
        ->and($remaining->first()->watched_at->toDateString())->toBe('2026-01-01');

    expect(rewatchState($user, $show)->watched)->toBeTrue();

    expect(fn () => app(CancelRewatch::class)->execute($user, $show))
        ->toThrow(NoRewatchToCancel::class);
});

test('resets derived show progress while keeping historical totals', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(StartRewatch::class)->execute($user, $show);

    $state = rewatchState($user, $show);

    expect($state->watched)->toBeFalse()
        ->and($state->watchedEpisodes)->toBe(0)
        ->and($state->nextEpisode->seasonNumber)->toBe(1)
        ->and($state->nextEpisode->number)->toBe(1)
        ->and($state->cycle)->toBe(2)
        ->and($state->completedCycles)->toBe(1)
        ->and($state->plays)->toBe(2);
});

test('does not carry a skipped season into a new cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);
    $season = $show->children()->firstOrFail();

    app(DropAttempt::class)->execute($user, $season, WatchedAt::releaseDateOrNow());
    app(StartRewatch::class)->execute($user, $show);

    $fresh = rewatchState($user, $show);

    expect($fresh->nextEpisode->number)->toBe(1)
        ->and($fresh->remainingEpisodes)->toBe(2);

    app(DropAttempt::class)->execute($user, $season, WatchedAt::releaseDateOrNow());

    $skippedAgain = rewatchState($user, $show);

    expect($skippedAgain->nextEpisode)->toBeNull()
        ->and($skippedAgain->skippedEpisodes)->toBe(2)
        ->and($skippedAgain->watchedEpisodes)->toBe(0);
});

test('stamps specials with the active cycle without moving the regular gap', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => ['2024-01-01'], 1 => ['2025-01-01']]);
    $regular = $show->children()->where('number', 1)->firstOrFail()->children()->firstOrFail();
    $special = $show->children()->where('number', 0)->firstOrFail()->children()->firstOrFail();

    app(MarkWatched::class)->execute($user, $regular, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    $cycle = app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $special, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    $state = rewatchState($user, $show);

    expect(Play::query()->where('media_item_id', $special->id)->firstOrFail()->watch_cycle_id)->toBe($cycle->id)
        ->and($state->watchedEpisodes)->toBe(0)
        ->and($state->nextEpisode->seasonNumber)->toBe(1)
        ->and($state->nextEpisode->number)->toBe(1);
});

test('returns a reset show to up next and keeps activity sorting based on all history', function () {
    $user = User::factory()->create();
    $older = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);
    $newer = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);
    $older->update(['title' => 'Older']);
    $newer->update(['title' => 'Newer']);

    app(MarkWatched::class)->execute($user, $older, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $newer, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));
    app(StartRewatch::class)->execute($user, $older);
    app(StartRewatch::class)->execute($user, $newer);

    $entries = app(ResolveUpNext::class)->execute($user);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->show->title)->toBe('Newer')
        ->and($entries[0]->episode->number)->toBe(1)
        ->and($entries[0]->watchedEpisodes)->toBe(0)
        ->and($entries[1]->show->title)->toBe('Older')
        ->and($entries[1]->episode->number)->toBe(1);
});

test('ignores a historical root drop but excludes a drop in the active cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $shows = app(WatchedShows::class);

    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    expect($shows->of($user))->toBeEmpty();

    app(StartRewatch::class)->execute($user, $show);

    expect($shows->of($user)->pluck('id')->all())->toBe([$show->id]);

    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    expect($shows->of($user))->toBeEmpty();
});

test('counts each fully completed show cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    $state = rewatchState($user, $show);

    expect($state->watched)->toBeTrue()
        ->and($state->cycle)->toBe(2)
        ->and($state->completedCycles)->toBe(2)
        ->and($state->plays)->toBe(4)
        ->and($state->watchedEpisodes)->toBe(2);
});

test('folds a season that airs mid-rewatch into the active cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(StartRewatch::class)->execute($user, $show);

    $season = MediaItem::factory()->season($show, 2)->create();
    MediaItem::factory()->episode($season, 1, '2026-06-01')->create();
    refreshTitle($show);

    $state = rewatchState($user, $show);

    // The new episode belongs to the run under way: the gap still points at the
    // beginning, the counters grew, and cycle one keeps its two plays. Its
    // "completed" badge goes though — no past cycle covers an episode that had
    // not aired yet.
    expect($state->cycle)->toBe(2)
        ->and($state->watchedEpisodes)->toBe(0)
        ->and($state->airedEpisodes)->toBe(3)
        ->and($state->nextEpisode->seasonNumber)->toBe(1)
        ->and($state->nextEpisode->number)->toBe(1)
        ->and($state->completedCycles)->toBe(0)
        ->and($state->plays)->toBe(2);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));

    $finished = rewatchState($user, $show);

    expect($finished->watched)->toBeTrue()
        ->and($finished->watchedEpisodes)->toBe(3)
        ->and($finished->completedCycles)->toBe(1)
        ->and($finished->plays)->toBe(5);
});

test('summarises every cycle by what it holds, not only by what was watched', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);
    $episodes = $show->children()->firstOrFail()->children()->orderBy('number')->get();

    // Cycle one: one episode watched, one skipped. A skip settles, so the run
    // reads as done even though only half of it was seen.
    app(MarkWatched::class)->execute($user, $episodes[0], WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, $episodes[1], WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $episodes[0], WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    // Cycle three is the one under way and holds nothing yet.
    app(StartRewatch::class)->execute($user, $show);

    // As the watched modal receives them.
    $rows = array_map(fn (WatchCycleSummary $row): array => $row->jsonSerialize(), app(WatchCycleSummaryResolver::class)->resolve($user, $show));

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray([
            'number' => 3,
            'watchedEpisodes' => 0,
            'airedEpisodes' => 2,
            'plays' => 0,
            'completed' => false,
            'isActive' => true,
            'canCancel' => true,
        ])
        // Abandoned halfway: still on the list, never cancellable — only the
        // newest cycle is.
        ->and($rows[1])->toMatchArray([
            'number' => 2,
            'watchedEpisodes' => 1,
            'plays' => 1,
            'completed' => false,
            'isActive' => false,
            'canCancel' => false,
        ])
        // The count the cancel warning shows is every entry, the skip included.
        ->and($rows[2])->toMatchArray([
            'number' => 1,
            'watchedEpisodes' => 1,
            'plays' => 2,
            'completed' => true,
            'isActive' => false,
            'canCancel' => false,
            'startedAt' => null,
        ])
        ->and($rows[2]['firstPlayAt'])->toStartWith('2026-01-01')
        ->and($rows[2]['lastPlayAt'])->toStartWith('2026-01-02');
});

test('serves a lone baseline cycle for a title nobody has touched', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('ui.media.cycles.index', ['type' => 'show', 'ref' => '999999']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', 1)
        ->assertJsonPath('data.0.plays', 0)
        ->assertJsonPath('data.0.isActive', true)
        ->assertJsonPath('data.0.canCancel', false);

    // A playable has no cycles at all — the request is refused before it
    // reaches the resolver.
    $this->actingAs($user)
        ->getJson(route('ui.media.cycles.index', ['type' => 'movie', 'ref' => '999999']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

test('lists the implicit and explicit watch cycles with derived summaries', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01', '2025-01-08']]);
    $episodes = $show->children()->firstOrFail()->children()->orderBy('number')->get();

    app(MarkWatched::class)->execute($user, $episodes[0], WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $episodes[1], WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    $cycle = WatchCycle::factory()->create([
        'user_id' => $user->id,
        'media_item_id' => $show->id,
        'number' => 2,
        'started_at' => CarbonImmutable::parse('2026-02-01'),
    ]);

    app(MarkWatched::class)->execute($user, $episodes[0], WatchedAt::at(CarbonImmutable::parse('2026-02-02')));

    $this->actingAs($user)
        ->getJson(route('ui.media.cycles.index', ['type' => 'show', 'ref' => $show->provider_ref]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', [
            'number' => 2,
            'startedAt' => '2026-02-01T00:00:00+00:00',
            'watchedEpisodes' => 1,
            'airedEpisodes' => 2,
            'plays' => 1,
            'completed' => false,
            'firstPlayAt' => '2026-02-02T00:00:00+00:00',
            'lastPlayAt' => '2026-02-02T00:00:00+00:00',
            'isActive' => true,
            'canCancel' => true,
        ])
        ->assertJsonPath('data.1', [
            'number' => 1,
            'startedAt' => null,
            'watchedEpisodes' => 2,
            'airedEpisodes' => 2,
            'plays' => 2,
            'completed' => true,
            'firstPlayAt' => '2026-01-01T00:00:00+00:00',
            'lastPlayAt' => '2026-01-02T00:00:00+00:00',
            'isActive' => false,
            'canCancel' => false,
        ]);

    $this->actingAs($user)
        ->getJson(route('ui.media.plays.index', ['type' => 'episode', 'ref' => $episodes[0]->provider_ref]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.cycle', 2)
        ->assertJsonPath('data.1.cycle', 1);

    expect($cycle->number)->toBe(2);
});

test('starts a rewatch over http and reports invalid reset requests as validation errors', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $emptyShow = makeShowTree([1 => ['2025-01-01']]);
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $this->actingAs($user)
        ->postJson(route('ui.media.cycles.store', ['type' => 'show', 'ref' => $show->provider_ref]))
        ->assertSuccessful();

    expect(WatchCycle::query()->where('media_item_id', $show->id)->value('number'))->toBe(2);

    $this->actingAs($user)
        ->postJson(route('ui.media.cycles.store', ['type' => 'show', 'ref' => $show->provider_ref]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cycle');

    $this->actingAs($user)
        ->postJson(route('ui.media.cycles.store', ['type' => 'show', 'ref' => $emptyShow->provider_ref]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cycle');

    $this->actingAs($user)
        ->postJson(route('ui.media.cycles.store', ['type' => 'movie', 'ref' => $movie->provider_ref]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

test('cancels only the latest rewatch over http and keeps the earlier ones', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2025-01-01']]);
    $episode = $show->children()->firstOrFail()->children()->firstOrFail();

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    $cycleTwo = app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));
    $cycleThree = app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-03-01')));

    $this->actingAs($user)
        ->deleteJson(route('ui.media.cycles.destroy', ['type' => 'show', 'ref' => $show->provider_ref]))
        ->assertSuccessful();

    expect(WatchCycle::query()->whereKey($cycleThree->id)->exists())->toBeFalse()
        ->and(WatchCycle::query()->whereKey($cycleTwo->id)->exists())->toBeTrue()
        ->and(Play::query()->inCycle($cycleTwo->id)->count())->toBe(1)
        ->and(Play::query()->inCycle(null)->count())->toBe(1);

    app(CancelRewatch::class)->execute($user, $show);

    $this->actingAs($user)
        ->deleteJson(route('ui.media.cycles.destroy', ['type' => 'show', 'ref' => $show->provider_ref]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cycle');
});

test('the run the watched modal offers to cancel is the one cancelling removes, whatever order the runs were made in', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);

    // An import met run 3 before run 2.
    WatchCycle::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'number' => 3, 'started_at' => CarbonImmutable::parse('2026-03-01')]);
    WatchCycle::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'number' => 2, 'started_at' => CarbonImmutable::parse('2026-02-01')]);
    projectLibrary($user);

    $rows = array_map(fn (WatchCycleSummary $row): array => $row->jsonSerialize(), app(WatchCycleSummaryResolver::class)->resolve($user, $show));
    $active = collect($rows)->firstWhere('isActive', true);

    expect($active['number'])->toBe(3)
        ->and(array_column($rows, 'number'))->toBe([3, 2, 1]);
});
