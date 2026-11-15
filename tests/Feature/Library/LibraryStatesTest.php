<?php

use App\Actions\Library\CreateList;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\ResumeAttempt;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\StartRewatch;
use App\Actions\Library\SyncListMembership;
use App\Catalog\Data\MediaSummary;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\PositionStatus;
use App\Library\LibraryStates;
use App\Library\LoggedSessions;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;

function resolveStates(User $user, MediaItem ...$items): array
{
    $summaries = array_map(
        fn (MediaItem $item) => new MediaSummary(type: $item->type, ref: $item->provider_ref, provider: $item->provider, title: $item->title),
        $items,
    );

    return app(LibraryStates::class)->resolve($user, $summaries);
}

test('a completion sharing a drop\'s exact date reads as watched, not dropped (id tiebreak)', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();
    $date = CarbonImmutable::parse('2020-05-01');

    // Both entries share the same watched_at (release-date mode); the completed
    // attempt is created after the drop, so creation order — not the equal
    // timestamp — must decide that the latest entry is the completion.
    Play::factory()->create([
        'user_id' => $user->id, 'media_item_id' => $game->id,
        'outcome' => PlayOutcome::Dropped, 'watched_at' => $date, 'amount' => 120,
    ]);
    Play::factory()->create([
        'user_id' => $user->id, 'media_item_id' => $game->id,
        'outcome' => PlayOutcome::Completed, 'watched_at' => $date, 'amount' => 200,
    ]);
    projectEveryLibrary();

    $states = resolveStates($user, $game);

    expect($states["game:{$game->provider_ref}"]->latestDropped)->toBeFalse()
        ->and($states["game:{$game->provider_ref}"]->watched)->toBeTrue()
        ->and($states["game:{$game->provider_ref}"]->status)->toBeNull();
});

test('a dropped playable exposes its drop date; a dropped container exposes its node drop date', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $movieDroppedAt = CarbonImmutable::parse('2024-03-15 20:00:00');

    Play::factory()->create([
        'user_id' => $user->id, 'media_item_id' => $movie->id,
        'outcome' => PlayOutcome::Dropped, 'watched_at' => $movieDroppedAt,
    ]);

    // A dropped show: the drop is a play on the container node itself.
    $show = makeShowTree([1 => ['2020-01-01']]);
    $showDroppedAt = CarbonImmutable::parse('2025-06-01 10:00:00');

    Play::factory()->create([
        'user_id' => $user->id, 'media_item_id' => $show->id,
        'outcome' => PlayOutcome::Dropped, 'watched_at' => $showDroppedAt,
    ]);
    projectEveryLibrary();

    $states = resolveStates($user, $movie, $show);

    expect($states["movie:{$movie->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and($states["movie:{$movie->provider_ref}"]->droppedAt)->toBe($movieDroppedAt->toIso8601String())
        ->and($states["show:{$show->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and($states["show:{$show->provider_ref}"]->droppedAt)->toBe($showDroppedAt->toIso8601String());
});

test('a specials season carries no progression of its own', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => ['2019-12-01', '2019-12-08'], 1 => ['2020-01-01']]);
    $specials = $show->children()->where('number', 0)->firstOrFail();
    $season1 = $show->children()->where('number', 1)->firstOrFail();

    app(MarkWatched::class)->execute(
        $user,
        $specials->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-01-01')),
    );

    $states = resolveStates($user, $show, $specials, $season1);
    $state = $states["season:{$specials->provider_ref}"];

    expect($state->inProgress)->toBeFalse()
        ->and($state->progression)->toBeFalse()
        ->and($state->watchedEpisodes)->toBeNull()
        ->and($state->airedEpisodes)->toBeNull()
        ->and($state->remainingEpisodes)->toBeNull()
        ->and($state->skippedEpisodes)->toBeNull()
        ->and($state->watchedMinutes)->toBeNull()
        ->and($state->remainingMinutes)->toBeNull()
        // The pointer stays: watching the next special explicitly still needs
        // to know which episode that is.
        ->and($state->nextEpisode->number)->toBe(2)
        // A watched special leaves the show and its ordinary seasons alone.
        ->and($states["show:{$show->provider_ref}"]->inProgress)->toBeFalse()
        ->and($states["season:{$season1->provider_ref}"]->progression)->toBeTrue();
});

test('a specials season with every special watched still reads as watched', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => ['2019-12-01', '2019-12-08'], 1 => ['2020-01-01']]);
    $specials = $show->children()->where('number', 0)->firstOrFail();

    app(MarkWatched::class)->execute($user, $specials, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $state = resolveStates($user, $specials)["season:{$specials->provider_ref}"];

    expect($state->watched)->toBeTrue()
        ->and($state->plays)->toBe(2)
        ->and($state->progression)->toBeFalse()
        ->and($state->watchedEpisodes)->toBeNull();
});

test('the season holding the first gap is in progress even with zero own plays', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
        3 => ['2022-01-01'],
    ]);

    [$season1, $season2, $season3] = $show->children()->orderBy('number')->get()->all();

    // Season 1 completed → the pointer moves to S02E01.
    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $states = resolveStates($user, $show, $season1, $season2, $season3);

    expect($states["show:{$show->provider_ref}"]->inProgress)->toBeTrue()
        ->and($states["season:{$season1->provider_ref}"]->inProgress)->toBeFalse()
        ->and($states["season:{$season2->provider_ref}"]->inProgress)->toBeTrue()
        ->and($states["season:{$season2->provider_ref}"]->watchedEpisodes)->toBe(0)
        ->and($states["season:{$season3->provider_ref}"]->inProgress)->toBeFalse();
});

test('the pointer season resolves in progress without its show in the set', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01'], 2 => ['2021-01-01']]);
    $season2 = $show->children()->where('number', 2)->firstOrFail();

    app(MarkWatched::class)->execute(
        $user,
        $show->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-01-01')),
    );

    // The season details page resolves the season alone (children are episodes).
    expect(resolveStates($user, $season2)["season:{$season2->provider_ref}"]->inProgress)->toBeTrue();
});

test('dropping a show closes only the season the user stood in', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
        3 => ['2022-01-01', '2022-01-08'],
        4 => ['2023-01-01', '2023-01-08'],
        5 => ['2024-01-01', '2024-01-08'],
    ]);

    [$season1, $season2, $season3, $season4, $season5] = $show->children()->orderBy('number')->get()->all();
    $droppedAt = CarbonImmutable::parse('2026-03-14 21:00:00');

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $season2, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));
    app(MarkWatched::class)->execute(
        $user,
        $season3->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-03-01')),
    );
    app(DropAttempt::class)->execute($user, $show, WatchedAt::at($droppedAt));

    $states = resolveStates($user, $show, $season1, $season2, $season3, $season4, $season5);

    // The two finished seasons keep their completion — giving up on the show
    // does not unwatch what was watched.
    expect($states["season:{$season1->provider_ref}"]->watched)->toBeTrue()
        ->and($states["season:{$season1->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season2->provider_ref}"]->watched)->toBeTrue()
        ->and($states["season:{$season2->provider_ref}"]->status)->toBeNull();

    // The season the run stopped in reads as dropped, on the show's date, and
    // keeps the episode it did watch.
    expect($states["season:{$season3->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and($states["season:{$season3->provider_ref}"]->droppedAt)->toBe($droppedAt->toIso8601String())
        ->and($states["season:{$season3->provider_ref}"]->inProgress)->toBeFalse()
        ->and($states["season:{$season3->provider_ref}"]->watchedEpisodes)->toBe(1);

    // Seasons never started are not dropped — there was nothing to give up on.
    expect($states["season:{$season4->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season4->provider_ref}"]->inProgress)->toBeFalse()
        ->and($states["season:{$season5->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season5->provider_ref}"]->inProgress)->toBeFalse();
});

test('pausing a show pauses its open season, with or without the show in the set', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
        3 => ['2022-01-01'],
    ]);

    [$season1, $season2, $season3] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute(
        $user,
        $season2->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-02-01')),
    );
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    $states = resolveStates($user, $show, $season1, $season2, $season3);

    expect($states["season:{$season2->provider_ref}"]->status)->toBe(PositionStatus::OnHold)
        ->and($states["season:{$season2->provider_ref}"]->inProgress)->toBeFalse()
        ->and($states["season:{$season2->provider_ref}"]->droppedAt)->toBeNull()
        ->and($states["season:{$season1->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season3->provider_ref}"]->status)->toBeNull();

    // A season met on a list has no show beside it — the pause still reaches it.
    $alone = resolveStates($user, $season2)["season:{$season2->provider_ref}"];

    expect($alone->status)->toBe(PositionStatus::OnHold)
        ->and($alone->inProgress)->toBeFalse();
});

test('a pointer season of a dropped show goes blank, never dropped', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
    ]);

    [$season1, $season2] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    // Season 2 read as in progress only because the pointer landed in it, with
    // nothing watched. The drop stops that claim, but a season nobody started
    // cannot be given up on — it reads as untouched.
    $state = resolveStates($user, $show, $season2)["season:{$season2->provider_ref}"];

    expect($state->status)->toBeNull()
        ->and($state->statusInherited)->toBeFalse()
        ->and($state->inProgress)->toBeFalse()
        ->and($state->watchedEpisodes)->toBe(0)
        // The show's status still rides along: the card explains the blank
        // season through the show.
        ->and($state->rootStatus)->toBe(PositionStatus::Dropped);
});

test('clearing a season that shows nothing leaves the show dropped', function (bool $discard) {
    $user = User::factory()->create();
    $show = makeShowTree([
        0 => ['2019-06-01'],
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
        3 => ['2022-01-01', '2022-01-08'],
    ]);

    [$specials, $season1, $season2, $season3] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute(
        $user,
        $season2->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-02-01')),
    );
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    // A finished season, one never started and the specials all show no
    // status — a clear sent for any of them (only the API can) has nothing
    // of the show's to take off, so the show's dated drop stays.
    foreach ([$season1, $season3, $specials] as $season) {
        expect(resolveStates($user, $season)["season:{$season->provider_ref}"]->statusInherited)->toBeFalse();

        app(ResumeAttempt::class)->execute($user, $season, $discard ? LoggedSessions::Discard : LoggedSessions::Keep);
    }

    expect(resolveStates($user, $show)["show:{$show->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and(resolveStates($user, $season2)["season:{$season2->provider_ref}"]->statusInherited)->toBeTrue();
})->with(['clear' => false, 'discard' => true]);

test('a drop from an earlier watch-through never makes a season climb', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
    ]);

    [$season1, $season2] = $show->children()->orderBy('number')->get()->all();

    // First watch-through: season 1 seen, the show given up on.
    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2025-01-01')));
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    // Second watch-through, now midway through season 2.
    app(StartRewatch::class)->execute($user, $show);
    app(MarkWatched::class)->execute(
        $user,
        $season2->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-02-01')),
    );

    // The old drop belongs to a closed cycle: the season wears nothing of it,
    // so a clear sent for it has no business reaching back into that history.
    $state = resolveStates($user, $season2)["season:{$season2->provider_ref}"];

    expect($state->rootStatus)->toBeNull()
        ->and($state->statusInherited)->toBeFalse()
        ->and($state->droppedAt)->toBeNull();

    app(ResumeAttempt::class)->execute($user, $season2);

    expect(Play::query()
        ->where('user_id', $user->id)
        ->where('media_item_id', $show->id)
        ->where('outcome', PlayOutcome::Dropped)
        ->count())->toBe(1);
});

test('clearing a season status clears the show it was inherited from', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
    ]);

    [$season1, $season2] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute(
        $user,
        $season2->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-02-01')),
    );
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    // One clear on the season, where the user sees the status — the show is
    // what actually holds it, so it has to be resumed as well.
    app(ResumeAttempt::class)->execute($user, $season2);

    $states = resolveStates($user, $show, $season2);

    expect($states["show:{$show->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season2->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season2->provider_ref}"]->rootStatus)->toBeNull()
        ->and($states["season:{$season2->provider_ref}"]->inProgress)->toBeTrue();
});

test('a season dropped under a dropped show comes off one level at a time', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
        3 => ['2022-01-01', '2022-01-08'],
    ]);

    [$season1, $season2, $season3] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute(
        $user,
        $season2->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-02-01')),
    );
    // Both levels closed: this season given up on, then the whole show.
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::releaseDateOrNow());
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    app(ResumeAttempt::class)->execute($user, $season2);

    $states = resolveStates($user, $show, $season2, $season3);

    // First clear takes the season's own drop only — the show's dated entry
    // belongs to a title the user did not touch. The season now reads the
    // show's status instead of its own, which the wording makes visible.
    expect($states["show:{$show->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and($states["season:{$season2->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and($states["season:{$season2->provider_ref}"]->statusInherited)->toBeTrue();

    // Season 3 was never started, so the show's drop leaves it blank rather
    // than marking it given up on.
    expect($states["season:{$season3->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season3->provider_ref}"]->inProgress)->toBeFalse();

    // Second clear, from the very same card, now reaches the show.
    app(ResumeAttempt::class)->execute($user, $season2);

    $states = resolveStates($user, $show, $season2);

    expect($states["show:{$show->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season2->provider_ref}"]->status)->toBeNull();
});

test('a season keeps its own status when the show carries one too', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
    ]);

    [$season1, $season2] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute(
        $user,
        $season2->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-02-01')),
    );

    // The user skipped THIS season, then gave up on the whole show. (A pause
    // lives on the show only: a season holds no open attempt of its own.)
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::at(CarbonImmutable::parse('2026-02-02')));
    app(DropAttempt::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-03-01')));

    $state = resolveStates($user, $season2)["season:{$season2->provider_ref}"];

    // Its own drop outranks the show's, is NOT worded as inherited, keeps its
    // own date, and the show's status rides along for the modal to explain.
    expect($state->status)->toBe(PositionStatus::Dropped)
        ->and($state->rootStatus)->toBe(PositionStatus::Dropped)
        ->and($state->statusInherited)->toBeFalse()
        ->and($state->inProgress)->toBeFalse()
        ->and($state->droppedAt)->toBe('2026-02-02T00:00:00+00:00');

    app(ResumeAttempt::class)->execute($user, $season2);

    $states = resolveStates($user, $show, $season2);

    // Two statuses are two statements: lifting the season's drop says nothing
    // about the show, whose drop is a dated history entry nobody asked to delete.
    // The season now reads the show's status, and says so.
    expect($states["show:{$show->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and($states["season:{$season2->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and($states["season:{$season2->provider_ref}"]->statusInherited)->toBeTrue();

    // And THAT one comes off on the show, which clears both levels at once.
    app(ResumeAttempt::class)->execute($user, $show);

    expect(resolveStates($user, $show, $season2)["season:{$season2->provider_ref}"]->status)->toBeNull();
});

test('clearing a skipped season leaves the show it was paused on paused', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
    ]);

    [$season1, $season2] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::releaseDateOrNow());
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    app(ResumeAttempt::class)->execute($user, $season2);

    // Un-skipping the season says nothing about the pause the user put on the
    // show, so the pause stays. The season itself was never started, so it
    // reads as untouched rather than taking the pause over.
    $states = resolveStates($user, $show, $season2);

    expect($states["show:{$show->provider_ref}"]->status)->toBe(PositionStatus::OnHold)
        ->and($states["season:{$season2->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season2->provider_ref}"]->statusInherited)->toBeFalse()
        ->and($states["season:{$season2->provider_ref}"]->inProgress)->toBeFalse();
});

test('clearing a show leaves a separately skipped season skipped', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01'],
        2 => ['2021-01-01'],
        3 => ['2022-01-01'],
    ]);

    [$season1, $season2] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::releaseDateOrNow());
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());
    app(ResumeAttempt::class)->execute($user, $show);

    // Skipping season 2 was its own statement — resuming the show says nothing
    // about it, so the cascade never runs downwards.
    $states = resolveStates($user, $show, $season2);

    expect($states["show:{$show->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season2->provider_ref}"]->status)->toBe(PositionStatus::Dropped);
});

test('a skipped season leaves the rest of a live show alone', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01'],
        2 => ['2021-01-01'],
        3 => ['2022-01-01'],
    ]);

    [$season1, $season2, $season3] = $show->children()->orderBy('number')->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::releaseDateOrNow());

    $states = resolveStates($user, $show, $season2, $season3);

    // Skipping one season says nothing about the show, so the pointer moves on
    // and season 3 keeps reading as the open one.
    expect($states["show:{$show->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season2->provider_ref}"]->status)->toBe(PositionStatus::Dropped)
        ->and($states["season:{$season3->provider_ref}"]->status)->toBeNull()
        ->and($states["season:{$season3->provider_ref}"]->inProgress)->toBeTrue();
});

test('seasons of an unwatched show are never in progress', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01'], 2 => ['2021-01-01']]);
    [$season1, $season2] = $show->children()->orderBy('number')->get()->all();

    $states = resolveStates($user, $show, $season1, $season2);

    expect($states["show:{$show->provider_ref}"]->inProgress)->toBeFalse()
        ->and($states["season:{$season1->provider_ref}"]->inProgress)->toBeFalse()
        ->and($states["season:{$season2->provider_ref}"]->inProgress)->toBeFalse();
});

test('next episode is the first aired gap in season and episode order', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        0 => ['2019-12-01'],
        1 => ['2020-01-01', '2020-01-08', '2020-01-15'],
        2 => ['2021-01-01', '2030-01-01'],
    ]);

    $season1 = $show->children()->where('number', 1)->firstOrFail();
    $season2 = $show->children()->where('number', 2)->firstOrFail();

    // S01E01 + S02E01 watched → the gap is S01E02, not S02E02.
    app(MarkWatched::class)->execute($user, $season1->children()->where('number', 1)->firstOrFail(), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $season2->children()->where('number', 1)->firstOrFail(), WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    $next = resolveStates($user, $show)["show:{$show->provider_ref}"]->nextEpisode;

    expect($next->seasonNumber)->toBe(1)
        ->and($next->number)->toBe(2);
});

test('next episode skips specials and disappears when the show is completed', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => ['2019-12-01'], 1 => ['2020-01-01']]);

    expect(resolveStates($user, $show)["show:{$show->provider_ref}"]->nextEpisode->seasonNumber)->toBe(1);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(resolveStates($user, $show)["show:{$show->provider_ref}"]->nextEpisode)->toBeNull();
});

test('a playable reports watched and play count', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));

    $state = resolveStates($user, $movie)["movie:{$movie->provider_ref}"];

    expect($state->watched)->toBeTrue()
        ->and($state->plays)->toBe(2)
        ->and($state->cycle)->toBe(1)
        ->and($state->completedCycles)->toBe(2)
        ->and($state->watchedAt)->toStartWith('2026-02-01');
});

test('a season is watched only when every aired episode is watched', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08', '2030-01-01']]);
    $season = $show->children()->firstOrFail();
    [$e1, $e2] = $season->children()->limit(2)->get()->all();

    app(MarkWatched::class)->execute($user, $e1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $key = "season:{$season->provider_ref}";

    $before = resolveStates($user, $season)[$key];

    expect($before->watched)->toBeFalse()
        ->and($before->watchedEpisodes)->toBe(1)
        ->and($before->airedEpisodes)->toBe(2);

    app(MarkWatched::class)->execute($user, $e2, WatchedAt::at(CarbonImmutable::parse('2026-01-02')));

    expect(resolveStates($user, $season)[$key]->watched)->toBeTrue();
});

test('a skipped season settles the show without counting as watched', function () {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => ['2020-01-01', '2020-01-08'],
        2 => ['2021-01-01', '2021-01-08'],
    ]);
    [$season1, $season2] = $show->children()->get()->all();

    app(MarkWatched::class)->execute($user, $season1, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::releaseDateOrNow());

    $state = resolveStates($user, $show)["show:{$show->provider_ref}"];

    // Skipping season 2 finishes the show — there is nothing left to watch —
    // but the counter reports the two episodes actually seen.
    expect($state->watched)->toBeTrue()
        ->and($state->nextEpisode)->toBeNull()
        ->and($state->watchedEpisodes)->toBe(2)
        ->and($state->airedEpisodes)->toBe(4);

    // Same rule one level down: the skipped season itself watched nothing.
    $skipped = resolveStates($user, $season2)["season:{$season2->provider_ref}"];

    expect($skipped->watchedEpisodes)->toBe(0)
        ->and($skipped->airedEpisodes)->toBe(2)
        ->and($skipped->status)->toBe(PositionStatus::Dropped);
});

test('a season is finished by what aired: an episode watched ahead of its air date does not finish a skipped run', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2030-01-01'], 2 => ['2021-01-01']]);
    [$season1, $season2] = $show->children()->get()->all();
    [$aired, $ahead] = $season1->children()->get()->all();

    app(MarkWatched::class)->execute($user, $ahead, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(DropAttempt::class)->execute($user, $aired, WatchedAt::at(CarbonImmutable::parse('2026-01-02')));
    app(DropAttempt::class)->execute($user, $season2, WatchedAt::releaseDateOrNow());

    // Every aired episode is settled, none of them watched: the card, the
    // drop and the history of runs all read it as not finished.
    expect(resolveStates($user, $show)["show:{$show->provider_ref}"]->watched)->toBeFalse();
});

test('a show ignores season 0 for status and aggregates plays across episodes', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => ['2019-12-01'], 1 => ['2020-01-01', '2020-01-08']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    $episode = $show->children()->where('number', 1)->firstOrFail()->children()->firstOrFail();
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-07-12')));

    $state = resolveStates($user, $show)["show:{$show->provider_ref}"];

    expect($state->watched)->toBeTrue()
        ->and($state->plays)->toBe(3)
        ->and($state->watchedEpisodes)->toBe(2)
        ->and($state->airedEpisodes)->toBe(2);
});

test('a newly aired episode row flips the show back to unwatched', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);
    $season = $show->children()->firstOrFail();

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-11')));

    expect(resolveStates($user, $show)["show:{$show->provider_ref}"]->watched)->toBeTrue();

    MediaItem::factory()->episode($season, 2, '2026-07-01')->create();
    refreshTitle($show);

    expect(resolveStates($user, $show)["show:{$show->provider_ref}"]->watched)->toBeFalse();
});

test('rating favorite lists and status map onto the entry', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);

    app(RateItem::class)->execute($user, $show, 8);
    app(SetFavorite::class)->execute($user, $show, true);
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    $list = app(CreateList::class)->execute($user, 'Fantasy');
    app(SyncListMembership::class)->execute($user, $show, [$list->id, $user->watchlist()->id]);

    $state = resolveStates($user, $show)["show:{$show->provider_ref}"];

    expect($state->rating)->toBe(8)
        ->and($state->favorite)->toBeTrue()
        ->and($state->status)->toBe(PositionStatus::OnHold)
        ->and($state->listIds)->toHaveCount(2);
});

test('states never leak between users', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($other, $movie, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(RateItem::class)->execute($other, $movie, 9);

    $state = resolveStates($user, $movie)["movie:{$movie->provider_ref}"];

    expect($state->watched)->toBeFalse()
        ->and($state->rating)->toBeNull();
});

test("a season prices an episode without a runtime at its show's length, asked with its show or alone", function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create(['runtime' => 45]);
    $season = MediaItem::factory()->season($show, 1)->create();
    MediaItem::factory()->episode($season, 1, '2020-01-01')->create(['runtime' => null]);
    MediaItem::factory()->episode($season, 2, '2020-01-08')->create(['runtime' => 30]);

    $withShow = resolveStates($user, $show, $season)["season:{$season->provider_ref}"];
    $alone = resolveStates($user, $season)["season:{$season->provider_ref}"];

    expect($withShow->remainingMinutes)->toBe(75)
        ->and($alone->remainingMinutes)->toBe(75);
});

test('episodes a provider left unnumbered stand level with the gap and count up to it', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 1)->create();
    [$first, $second] = collect(['a', 'b', 'c'])->map(fn (string $suffix) => MediaItem::factory()->episode($season, 1)->create([
        'provider_ref' => "{$season->provider_ref}:{$suffix}",
        'number' => null,
    ]))->all();

    app(MarkWatched::class)->execute($user, $first, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $state = resolveStates($user, $show)["show:{$show->provider_ref}"];

    // The three cannot be told apart by number: the gap is the first unsettled
    // of them, and its place counts every one of them, as the progress
    // counter reads it.
    expect($state->nextEpisode?->ref)->toBe($second->provider_ref)
        ->and($state->position)->toBe(3);
});
