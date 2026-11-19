<?php

use App\Actions\Library\AddToList;
use App\Actions\Library\BackCreatorTitles;
use App\Actions\Library\BackfillCreatorFollow;
use App\Actions\Library\BackfillCycle;
use App\Actions\Library\BackfillDrop;
use App\Actions\Library\BackfillFollow;
use App\Actions\Library\BackfillPlay;
use App\Actions\Library\BackfillRating;
use App\Actions\Library\BackfillStatus;
use App\Actions\Library\CancelRewatch;
use App\Actions\Library\ClearUserData;
use App\Actions\Library\CreateList;
use App\Actions\Library\DeleteList;
use App\Actions\Library\DeletePlay;
use App\Actions\Library\DiscardAttempt;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\DuplicateList;
use App\Actions\Library\LogProgress;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\RemoveListItem;
use App\Actions\Library\RemoveRating;
use App\Actions\Library\ReorderListItems;
use App\Actions\Library\ReorderLists;
use App\Actions\Library\ResumeAttempt;
use App\Actions\Library\SetCreatorFollow;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetFollow;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\StartRewatch;
use App\Actions\Library\SyncCreatorLists;
use App\Actions\Library\SyncListMembership;
use App\Actions\Library\UndoDrop;
use App\Actions\Library\UnmarkWatched;
use App\Actions\Library\UpdateList;
use App\Actions\Library\UpdatePlayAmount;
use App\Actions\Library\WithdrawCreatorBacking;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Library\BackfillBatch;
use App\Library\Data\ListTarget;
use App\Library\Events\CreatorBackingWithdrawn;
use App\Library\Events\CreatorTitlesBacked;
use App\Library\Events\FavoriteSet;
use App\Library\Events\FollowChanged;
use App\Library\Events\LibraryCleared;
use App\Library\Events\ListCreated;
use App\Library\Events\ListDeleted;
use App\Library\Events\ListItemAdded;
use App\Library\Events\ListItemRemoved;
use App\Library\Events\ListItemsReordered;
use App\Library\Events\ListsReordered;
use App\Library\Events\ListUpdated;
use App\Library\Events\MediaStatusChanged;
use App\Library\Events\PlayAmountChanged;
use App\Library\Events\PlayRecorded;
use App\Library\Events\PlayRemoved;
use App\Library\Events\ProgressLogged;
use App\Library\Events\RatingChanged;
use App\Library\Events\RatingRemoved;
use App\Library\Events\RewatchCancelled;
use App\Library\Events\RewatchStarted;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\Play;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Every change to the library is announced — the projection and any outgoing
 * channel can trust the events because no write path is silent. A new action
 * in app/Actions/Library has to bring its scenario here. A scenario that needs
 * a library to act on prepares it apart, before the events are watched, so
 * what the preparation announces cannot pass for the write's own event.
 *
 * @return array<class-string, array{0: class-string, 1: Closure}|array{0: class-string, 1: Closure(User): mixed, 2: Closure}>
 */
function libraryWrites(): array
{
    $at = CarbonImmutable::parse('2026-03-01 20:00');
    $watched = function (User $user) use ($at): MediaItem {
        $movie = MediaItem::factory()->movie()->create();
        app(MarkWatched::class)->execute($user, $movie, WatchedAt::at($at));

        return $movie;
    };
    $rewatching = function (User $user) use ($at): MediaItem {
        $show = makeShowTree([1 => ['2020-01-01']]);
        app(MarkWatched::class)->execute($user, $show, WatchedAt::at($at));
        app(StartRewatch::class)->execute($user, $show);

        return $show;
    };
    $person = fn (): CreatorRef => new CreatorRef(CreatorKind::Person, 'tmdb', Person::factory()->create()->provider_ref);
    $listed = function (User $user) use ($watched): MediaList {
        $list = app(CreateList::class)->execute($user, 'Original');
        app(SyncListMembership::class)->execute($user, $watched($user), [$list->id]);

        return $list->refresh();
    };

    return [
        AddToList::class => [ListItemAdded::class, fn (User $user) => app(AddToList::class)->execute($user, $user->watchlist(), ListTarget::item(MediaItem::factory()->movie()->create()->id))],
        BackCreatorTitles::class => [CreatorTitlesBacked::class, fn (User $user) => app(BackCreatorTitles::class)->execute($user, CreatorKind::Person, Person::factory()->create()->id, [MediaItem::factory()->movie()->create()->id])],
        BackfillCreatorFollow::class => [FollowChanged::class, fn (User $user) => app(BackfillCreatorFollow::class)->execute($user, $person(), 'Bryan Cranston', FollowMode::Follow, 'en')],
        BackfillCycle::class => [RewatchStarted::class, fn (User $user) => app(BackfillCycle::class)->execute($user, makeShowTree([1 => ['2020-01-01']]), 2, $at)],
        BackfillFollow::class => [FollowChanged::class, fn (User $user) => app(BackfillFollow::class)->execute($user, MediaItem::factory()->movie()->create(), FollowMode::Mute)],
        BackfillDrop::class => [PlayRecorded::class, fn (User $user) => app(BackfillDrop::class)->execute($user, MediaItem::factory()->movie()->create(), WatchedAt::at($at), BackfillBatch::begin($user))],
        BackfillPlay::class => [PlayRecorded::class, fn (User $user) => app(BackfillPlay::class)->execute($user, MediaItem::factory()->movie()->create(), $at)],
        BackfillRating::class => [RatingChanged::class, fn (User $user) => app(BackfillRating::class)->execute($user, MediaItem::factory()->movie()->create(), 7, $at)],
        BackfillStatus::class => [MediaStatusChanged::class, fn (User $user) => app(BackfillStatus::class)->execute($user, MediaItem::factory()->game()->create(), MediaUserStatus::OnHold, BackfillBatch::begin($user), $at)],
        CancelRewatch::class => [RewatchCancelled::class, $rewatching, fn (User $user, MediaItem $show) => app(CancelRewatch::class)->execute($user, $show)],
        ClearUserData::class => [LibraryCleared::class, fn (User $user) => app(ClearUserData::class)->execute($user)],
        CreateList::class => [ListCreated::class, fn (User $user) => app(CreateList::class)->execute($user, 'Best of')],
        DeleteList::class => [ListDeleted::class, fn (User $user) => app(CreateList::class)->execute($user, 'Gone'), fn (User $user, MediaList $list) => app(DeleteList::class)->execute($user, $list)],
        DeletePlay::class => [PlayRemoved::class, $watched, fn (User $user) => app(DeletePlay::class)->execute($user, Play::query()->firstOrFail())],
        DropAttempt::class => [PlayRecorded::class, fn (User $user) => app(DropAttempt::class)->execute($user, MediaItem::factory()->movie()->create(), WatchedAt::at($at))],
        DiscardAttempt::class => [MediaStatusChanged::class, function (User $user): MediaItem {
            $game = MediaItem::factory()->game()->create();
            app(LogProgress::class)->execute($user, $game, 90, null);

            return $game;
        }, fn (User $user, MediaItem $game) => app(DiscardAttempt::class)->execute($user, $game)],
        DuplicateList::class => [ListItemAdded::class, $listed, fn (User $user, MediaList $list) => app(DuplicateList::class)->execute($user, $list)],
        LogProgress::class => [ProgressLogged::class, fn (User $user) => app(LogProgress::class)->execute($user, MediaItem::factory()->book()->create(), 20, null)],
        MarkWatched::class => [PlayRecorded::class, fn (User $user) => $watched($user)],
        RateItem::class => [RatingChanged::class, fn (User $user) => app(RateItem::class)->execute($user, MediaItem::factory()->movie()->create(), 8)],
        RemoveListItem::class => [ListItemRemoved::class, $listed, fn (User $user, MediaList $list) => app(RemoveListItem::class)->execute($list, $list->items()->firstOrFail())],
        RemoveRating::class => [RatingRemoved::class, function (User $user): MediaItem {
            $movie = MediaItem::factory()->movie()->create();
            app(RateItem::class)->execute($user, $movie, 8);

            return $movie;
        }, fn (User $user, MediaItem $movie) => app(RemoveRating::class)->execute($user, $movie)],
        ReorderListItems::class => [ListItemsReordered::class, fn (User $user) => app(CreateList::class)->execute($user, 'Ordered'), fn (User $user, MediaList $list) => app(ReorderListItems::class)->execute($list, [])],
        ReorderLists::class => [ListsReordered::class, fn (User $user) => app(ReorderLists::class)->execute($user, [])],
        ResumeAttempt::class => [MediaStatusChanged::class, function (User $user): MediaItem {
            $game = MediaItem::factory()->game()->create();
            app(SetMediaStatus::class)->execute($user, $game, MediaUserStatus::OnHold);

            return $game;
        }, fn (User $user, MediaItem $game) => app(ResumeAttempt::class)->execute($user, $game)],
        SetCreatorFollow::class => [FollowChanged::class, fn (User $user) => app(SetCreatorFollow::class)->execute($user, $person(), FollowMode::Follow, 'en')],
        SetFavorite::class => [FavoriteSet::class, fn (User $user) => app(SetFavorite::class)->execute($user, MediaItem::factory()->movie()->create(), true)],
        SetFollow::class => [FollowChanged::class, fn (User $user) => app(SetFollow::class)->execute($user, MediaItem::factory()->movie()->create(), FollowMode::Follow)],
        SetMediaStatus::class => [MediaStatusChanged::class, fn (User $user) => app(SetMediaStatus::class)->execute($user, MediaItem::factory()->game()->create(), MediaUserStatus::OnHold)],
        StartRewatch::class => [RewatchStarted::class, fn (User $user) => $rewatching($user)],
        SyncCreatorLists::class => [ListItemAdded::class, fn (User $user) => app(SyncCreatorLists::class)->execute($user, $person(), [app(CreateList::class)->execute($user, 'People')->id], 'en')],
        SyncListMembership::class => [ListItemAdded::class, fn (User $user) => app(SyncListMembership::class)->execute($user, MediaItem::factory()->movie()->create(), [$user->watchlist()->id])],
        UndoDrop::class => [PlayRemoved::class, function (User $user) use ($at): MediaItem {
            $movie = MediaItem::factory()->movie()->create();
            app(DropAttempt::class)->execute($user, $movie, WatchedAt::at($at));

            return $movie;
        }, fn (User $user, MediaItem $movie) => app(UndoDrop::class)->execute($user, $movie)],
        UnmarkWatched::class => [PlayRemoved::class, $watched, fn (User $user, MediaItem $movie) => app(UnmarkWatched::class)->execute($user, $movie)],
        UpdateList::class => [ListUpdated::class, fn (User $user) => app(CreateList::class)->execute($user, 'Old name'), fn (User $user, MediaList $list) => app(UpdateList::class)->execute($list, 'New name', null, false)],
        UpdatePlayAmount::class => [PlayAmountChanged::class, function (User $user) use ($at): Play {
            app(MarkWatched::class)->execute($user, MediaItem::factory()->game()->create(), WatchedAt::at($at), 600);

            return Play::query()->firstOrFail();
        }, fn (User $user, Play $play) => app(UpdatePlayAmount::class)->execute($user, $play, 900)],
        WithdrawCreatorBacking::class => [CreatorBackingWithdrawn::class, function (User $user): Person {
            $person = Person::factory()->create();
            app(BackCreatorTitles::class)->execute($user, CreatorKind::Person, $person->id, [MediaItem::factory()->movie()->create()->id]);

            return $person;
        }, fn (User $user, Person $person) => app(WithdrawCreatorBacking::class)->execute(CreatorKind::Person, $person->id, $user)],
    ];
}

test('every action that writes the library has a scenario here', function () {
    $actions = collect(glob(app_path('Actions/Library/*.php')) ?: [])
        ->map(fn (string $path): string => 'App\\Actions\\Library\\'.basename($path, '.php'))
        ->sort()->values()->all();

    expect(collect(array_keys(libraryWrites()))->sort()->values()->all())->toBe($actions);
});

test('a write announces what it changed', function (string $action) {
    enableGamesModule();
    enableBooksModule();
    // A followed person's titles are fetched by a queued sync this test has no provider for.
    Queue::fake();

    $scenario = libraryWrites()[$action];
    $user = User::factory()->create();
    [$event, $prepare, $write] = count($scenario) === 3 ? $scenario : [$scenario[0], fn (): null => null, $scenario[1]];
    $prepared = $prepare($user);

    Event::fake([$event]);
    $write($user, $prepared);

    Event::assertDispatched($event, fn (object $announced): bool => $announced->userId === $user->id);

    // And every history entry it wrote says where it came in from.
    expect(Play::query()->where('user_id', $user->id)->whereNull('source')->exists())->toBeFalse();
})->with(fn (): array => array_keys(libraryWrites()));
