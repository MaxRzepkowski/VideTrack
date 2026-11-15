<?php

use App\Actions\Library\CancelRewatch;
use App\Actions\Library\DeleteList;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\LogProgress;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\RemoveRating;
use App\Actions\Library\SetCreatorFollow;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetFollow;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\StartRewatch;
use App\Actions\Library\SyncListMembership;
use App\Actions\Library\UndoDrop;
use App\Actions\Library\UnmarkWatched;
use App\Actions\Media\SendMediaNotification;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Events\Media\MediaEventDetected;
use App\Library\Events\FavoriteSet;
use App\Library\Events\FollowChanged;
use App\Library\Events\ListDeleted;
use App\Library\Events\ListItemAdded;
use App\Library\Events\ListItemRemoved;
use App\Library\Events\MediaStatusChanged;
use App\Library\Events\PlayAmountChanged;
use App\Library\Events\PlayRecorded;
use App\Library\Events\PlayRemoved;
use App\Library\Events\ProgressLogged;
use App\Library\Events\RatingChanged;
use App\Library\Events\RatingRemoved;
use App\Library\MutationContext;
use App\Library\MutationSource;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

test('marking a movie announces the play it created', function () {
    Event::fake([PlayRecorded::class]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-07-01 21:30')));

    Event::assertDispatched(PlayRecorded::class, function (PlayRecorded $event) use ($user, $movie): bool {
        return $event->userId === $user->id
            && $event->itemId === $movie->id
            && $event->itemType === 'movie'
            && $event->rootId === $movie->id
            && $event->source->value() === 'ui'
            && count($event->plays) === 1
            && $event->plays[0]->playId === Play::query()->firstOrFail()->id
            && $event->plays[0]->outcome === PlayOutcome::Completed;
    });
});

test('marking a show announces every episode play in one event', function () {
    Event::fake([PlayRecorded::class]);

    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08', '2020-01-15']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));

    Event::assertDispatchedTimes(PlayRecorded::class, 1);
    Event::assertDispatched(PlayRecorded::class, function (PlayRecorded $event) use ($show): bool {
        return $event->itemId === $show->id
            && $event->rootId === $show->id
            && count($event->plays) === 3
            && count(array_unique(array_column($event->plays, 'playId'))) === 3;
    });
});

test('marking an episode carries the show as its root', function () {
    Event::fake([PlayRecorded::class]);

    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);
    $episode = MediaItem::query()->where('type', 'episode')->firstOrFail();

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));

    Event::assertDispatched(PlayRecorded::class, fn (PlayRecorded $event): bool => $event->itemId === $episode->id
        && $event->itemType === 'episode'
        && $event->rootId === $show->id);
});

test('marking a show with nothing left to settle announces nothing', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));

    Event::fake([PlayRecorded::class]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-08-01')));

    Event::assertNotDispatched(PlayRecorded::class);
});

test('unmarking announces the entries it deleted', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));
    $playIds = Play::query()->pluck('id')->all();

    Event::fake([PlayRemoved::class]);

    app(UnmarkWatched::class)->execute($user, $show);

    Event::assertDispatched(PlayRemoved::class, function (PlayRemoved $event) use ($user, $show, $playIds): bool {
        sort($playIds);
        $announced = $event->playIds;
        sort($announced);

        return $event->userId === $user->id
            && $event->itemId === $show->id
            && $event->rootId === $show->id
            && $announced === $playIds;
    });
});

test('unmarking a position with no history announces nothing', function () {
    Event::fake([PlayRemoved::class]);

    app(UnmarkWatched::class)->execute(User::factory()->create(), MediaItem::factory()->movie()->create());

    Event::assertNotDispatched(PlayRemoved::class);
});

test('dropping an attempt announces the dropped play, undoing announces the removal', function () {
    Event::fake([PlayRecorded::class, PlayRemoved::class]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(DropAttempt::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-07-01 18:00')));

    Event::assertDispatched(PlayRecorded::class, fn (PlayRecorded $event): bool => $event->userId === $user->id
        && $event->itemId === $movie->id
        && $event->rootId === $movie->id
        && $event->plays[0]->playId === Play::query()->firstOrFail()->id
        && $event->plays[0]->outcome === PlayOutcome::Dropped
        && str_starts_with($event->plays[0]->watchedAt, '2026-07-01T18:00'));

    app(UndoDrop::class)->execute($user, $movie);

    Event::assertDispatched(PlayRemoved::class, fn (PlayRemoved $event): bool => $event->itemId === $movie->id
        && count($event->playIds) === 1);
});

test('deleting one play from the history announces just that entry', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);
    $episode = MediaItem::query()->where('type', 'episode')->firstOrFail();

    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));
    $play = Play::query()->firstOrFail();

    Event::fake([PlayRemoved::class]);

    $this->actingAs($user)->deleteJson(route('ui.plays.destroy', ['play' => $play->id]))->assertSuccessful();

    Event::assertDispatched(PlayRemoved::class, fn (PlayRemoved $e): bool => $e->userId === $user->id
        && $e->itemId === $episode->id
        && $e->rootId === $show->id
        && $e->playIds === [$play->id]);

    expect(Play::count())->toBe(0);
});

test('correcting a play amount announces both sides, a no-op announces nothing', function () {
    enableGamesModule();

    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();

    app(MarkWatched::class)->execute($user, $game, WatchedAt::at(CarbonImmutable::parse('2026-07-01')), 1200);
    $play = Play::query()->firstOrFail();

    Event::fake([PlayAmountChanged::class]);

    $this->actingAs($user)->patchJson(route('ui.plays.update', ['play' => $play->id]), ['amount' => 1500])->assertSuccessful();
    $this->actingAs($user)->patchJson(route('ui.plays.update', ['play' => $play->id]), ['amount' => 1500])->assertSuccessful();

    Event::assertDispatchedTimes(PlayAmountChanged::class, 1);
    Event::assertDispatched(PlayAmountChanged::class, fn (PlayAmountChanged $e): bool => $e->playId === $play->id
        && $e->itemId === $game->id
        && $e->amount === 1500
        && $e->previous === 1200);

    expect($play->refresh()->amount)->toBe(1500);
});

test('removing a list entry by hand announces the removal', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $list = $user->lists()->create(['title' => 'Best of', 'description' => null, 'is_public' => false]);

    app(SyncListMembership::class)->execute($user, $movie, [$list->id]);
    $entry = $list->items()->firstOrFail();

    Event::fake([ListItemRemoved::class]);

    $this->actingAs($user)->deleteJson(route('ui.lists.items.destroy', ['list' => $list, 'item' => $entry]))->assertNoContent();

    Event::assertDispatched(ListItemRemoved::class, fn (ListItemRemoved $e): bool => $e->userId === $user->id
        && $e->listId === $list->id
        && $e->target->itemId === $movie->id);
});

test('rating announces the score it replaced, clearing announces the removal', function () {
    Event::fake([RatingChanged::class, RatingRemoved::class]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(RateItem::class)->execute($user, $movie, 7);
    app(RateItem::class)->execute($user, $movie, 9);

    Event::assertDispatched(RatingChanged::class, fn (RatingChanged $e): bool => $e->rating === 7 && $e->previous === null);
    Event::assertDispatched(RatingChanged::class, fn (RatingChanged $e): bool => $e->rating === 9 && $e->previous === 7);

    app(RemoveRating::class)->execute($user, $movie);

    Event::assertDispatched(RatingRemoved::class, fn (RatingRemoved $e): bool => $e->itemId === $movie->id);
});

test('clearing a rating that was never set announces nothing', function () {
    Event::fake([RatingRemoved::class]);

    app(RemoveRating::class)->execute(User::factory()->create(), MediaItem::factory()->movie()->create());

    Event::assertNotDispatched(RatingRemoved::class);
});

test('favoriting announces the change once, repeating it announces nothing', function () {
    Event::fake([FavoriteSet::class]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(SetFavorite::class)->execute($user, $movie, true);
    app(SetFavorite::class)->execute($user, $movie, true);

    Event::assertDispatchedTimes(FavoriteSet::class, 1);
    Event::assertDispatched(FavoriteSet::class, fn (FavoriteSet $e): bool => $e->favorite === true);

    app(SetFavorite::class)->execute($user, $movie, false);
    app(SetFavorite::class)->execute($user, $movie, false);

    Event::assertDispatchedTimes(FavoriteSet::class, 2);
    Event::assertDispatched(FavoriteSet::class, fn (FavoriteSet $e): bool => $e->favorite === false);
});

test('a status change announces both sides, a repeat announces nothing', function () {
    Event::fake([MediaStatusChanged::class]);

    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create();

    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    Event::assertDispatchedTimes(MediaStatusChanged::class, 1);
    Event::assertDispatched(MediaStatusChanged::class, fn (MediaStatusChanged $e): bool => $e->itemId === $show->id
        && $e->status === MediaUserStatus::OnHold
        && $e->previous === null);

    app(SetMediaStatus::class)->execute($user, $show, null);

    Event::assertDispatched(MediaStatusChanged::class, fn (MediaStatusChanged $e): bool => $e->status === null
        && $e->previous === MediaUserStatus::OnHold);
});

test('logging a session announces the new counter', function () {
    enableBooksModule();
    Event::fake([ProgressLogged::class, PlayRecorded::class]);

    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create();

    app(LogProgress::class)->execute($user, $book, 40, null);

    Event::assertDispatched(ProgressLogged::class, fn (ProgressLogged $e): bool => $e->itemId === $book->id
        && $e->added === 40
        && $e->set === null
        && $e->progress === 40);
    Event::assertNotDispatched(PlayRecorded::class);
});

test('reaching the last page announces the play alone', function () {
    enableBooksModule();
    Event::fake([ProgressLogged::class, PlayRecorded::class]);

    $user = User::factory()->create();
    $book = MediaItem::factory()->book()->create();

    app(LogProgress::class)->execute($user, $book, null, 320);

    Event::assertNotDispatched(ProgressLogged::class);
    Event::assertDispatched(PlayRecorded::class, fn (PlayRecorded $e): bool => $e->itemId === $book->id);
});

test('list membership announces each entry added and removed', function () {
    Event::fake([ListItemAdded::class, ListItemRemoved::class]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $list = $user->lists()->create(['title' => 'Best of', 'description' => null, 'is_public' => false]);

    app(SyncListMembership::class)->execute($user, $movie, [$list->id]);

    Event::assertDispatched(ListItemAdded::class, fn (ListItemAdded $e): bool => $e->userId === $user->id
        && $e->listId === $list->id
        && $e->target->itemId === $movie->id
        && $e->target->personId === null);

    app(SyncListMembership::class)->execute($user, $movie, []);

    Event::assertDispatched(ListItemRemoved::class, fn (ListItemRemoved $e): bool => $e->listId === $list->id
        && $e->target->itemId === $movie->id);
});

test('a delivered media event is announced, a deduplicated one is not', function () {
    Event::fake([MediaEventDetected::class]);

    $user = User::factory()->create();
    $payload = premiereEvent('The Matrix', '603');

    expect(app(SendMediaNotification::class)->execute($user, $payload))->toBeTrue();
    expect(app(SendMediaNotification::class)->execute($user, $payload))->toBeFalse();

    Event::assertDispatchedTimes(MediaEventDetected::class, 1);
    Event::assertDispatched(MediaEventDetected::class, fn (MediaEventDetected $e): bool => $e->userId === $user->id
        && $e->event->key === 'released:movie:603:2026-10-10');
});

test('the entry layer decides the source the actions announce', function () {
    Event::fake([RatingChanged::class]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MutationContext::class)->run(
        MutationSource::scrobble('jellyfin'),
        fn () => app(RateItem::class)->execute($user, $movie, 8),
    );

    Event::assertDispatched(RatingChanged::class, fn (RatingChanged $e): bool => $e->source->value() === 'scrobble:jellyfin');
});

test('an event does not escape a rolled back transaction', function () {
    $seen = [];
    Event::listen(PlayRecorded::class, function (PlayRecorded $event) use (&$seen): void {
        $seen[] = $event;
    });

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    try {
        DB::transaction(function () use ($user, $movie): void {
            app(MarkWatched::class)->execute($user, $movie, WatchedAt::at(CarbonImmutable::parse('2026-07-01')));

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($seen)->toBe([])
        ->and(Play::count())->toBe(0);
});

test('ringing a title bell announces the mode and the one it replaced, repeating it announces nothing', function () {
    Event::fake([FollowChanged::class]);

    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $bell = app(SetFollow::class);

    $bell->execute($user, $movie, FollowMode::Follow);
    $bell->execute($user, $movie, FollowMode::Follow);
    $bell->execute($user, $movie, FollowMode::Mute);
    $bell->execute($user, $movie, null);

    Event::assertDispatchedTimes(FollowChanged::class, 3);
    Event::assertDispatched(FollowChanged::class, fn (FollowChanged $e): bool => $e->itemId === $movie->id
        && $e->mode === FollowMode::Follow && $e->previousMode === null && $e->creatorKind === null);
    Event::assertDispatched(FollowChanged::class, fn (FollowChanged $e): bool => $e->mode === FollowMode::Mute && $e->previousMode === FollowMode::Follow);
    Event::assertDispatched(FollowChanged::class, fn (FollowChanged $e): bool => $e->mode === null && $e->previousMode === FollowMode::Mute);
});

test('ringing a creator bell announces the creator, clearing one nobody rang announces nothing', function () {
    Event::fake([FollowChanged::class]);

    $user = User::factory()->create();
    $person = Person::factory()->create();
    $bell = app(SetCreatorFollow::class);
    $ref = new CreatorRef(CreatorKind::Person, $person->provider, $person->provider_ref);

    $bell->execute($user, new CreatorRef(CreatorKind::Person, 'tmdb', 'nobody'), null, 'en');
    $bell->execute($user, $ref, FollowMode::Follow, 'en');

    Event::assertDispatchedTimes(FollowChanged::class, 1);
    Event::assertDispatched(FollowChanged::class, fn (FollowChanged $e): bool => $e->creatorKind === CreatorKind::Person
        && $e->creatorId === $person->id && $e->itemId === null && $e->mode === FollowMode::Follow);
});

test('deleting a list announces it', function () {
    Event::fake([ListDeleted::class]);

    $user = User::factory()->create();
    $list = $user->lists()->create(['title' => 'Mine']);

    app(DeleteList::class)->execute($user, $list);

    Event::assertDispatched(ListDeleted::class, fn (ListDeleted $e): bool => $e->userId === $user->id && $e->listId === $list->id);
    expect($user->lists()->whereKey($list->id)->exists())->toBeFalse();
});

test('giving the score a title already has is no write and no fact', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    app(RateItem::class)->execute($user, $movie, 7);

    Event::fake([RatingChanged::class]);
    app(RateItem::class)->execute($user, $movie, 7);

    Event::assertNotDispatched(RatingChanged::class);
});

test('what a rewatch and an unmark take away is announced too', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);
    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-03-01')));
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    Event::fake([MediaStatusChanged::class, PlayRemoved::class]);
    app(StartRewatch::class)->execute($user, $show);

    Event::assertDispatched(MediaStatusChanged::class, fn (MediaStatusChanged $e): bool => $e->itemId === $show->id
        && $e->status === null && $e->previous === MediaUserStatus::OnHold);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-04-01')));
    app(CancelRewatch::class)->execute($user, $show);

    Event::assertDispatched(PlayRemoved::class, fn (PlayRemoved $e): bool => $e->rootId === $show->id && count($e->playIds) === 1);

    $game = MediaItem::factory()->game()->create();
    app(SetMediaStatus::class)->execute($user, $game, MediaUserStatus::OnHold);
    app(UnmarkWatched::class)->execute($user, $game);

    Event::assertDispatched(MediaStatusChanged::class, fn (MediaStatusChanged $e): bool => $e->itemId === $game->id
        && $e->status === null && $e->previous === MediaUserStatus::OnHold);
});
