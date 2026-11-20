<?php

use App\Actions\Library\DropAttempt;
use App\Actions\Library\LogProgress;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetFollow;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\StartRewatch;
use App\Actions\Library\SyncListMembership;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Library\AttemptPolicy;
use App\Library\LibraryStates;
use App\Library\WatchedAt;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Rating;
use App\Models\User;
use App\Tracking\Data\TrackingFacts;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;
use App\Tracking\TrackedAudience;
use App\Tracking\TrackingFactsQuery;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * One rule behind the calendar, the countdowns and both kinds of
 * notification: a title falls in one row of the tracking matrix — on hold,
 * dropped, backlog, history, your bell, a creator's bell, in that order — and
 * that row's toggle decides for it.
 */

/** A show whose second episode airs in five days, its first long out. */
function airingShow(string $title): MediaItem
{
    $show = makeShowTree([1 => ['2020-01-01', today()->addDays(5)->toDateString()]]);
    $show->update(['title' => $title]);

    return $show;
}

function watchPilot(User $user, MediaItem $show): void
{
    app(MarkWatched::class)->execute($user, $show->children()->firstOrFail()->children()->where('number', 1)->firstOrFail(), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
}

function putOnBacklog(User $user, MediaItem $item): void
{
    app(SyncListMembership::class)->execute($user, $item, [$user->watchlist()->id]);
}

/**
 * @param  array<string, bool>  $toggles
 */
function setToggles(User $user, array $toggles): void
{
    $user->preferences = $toggles;
    $user->save();
}

/**
 * The titles a surface carries in the next two months: the show's title for an
 * episode, the title's own otherwise.
 *
 * @return list<string>
 */
function carriedTitles(User $user, Surface $surface = Surface::Calendar): array
{
    return app(ReleaseFeed::class)
        ->entries($user->refresh(), $surface, today()->toDateString(), today()->addDays(60)->toDateString())
        ->map(fn (ReleaseEntry $entry): string => $entry->showTitle ?? $entry->media->title)
        ->unique()
        ->sort()
        ->values()
        ->all();
}

test('a status outranks the bell: a dropped title rung by hand answers to the dropped toggle', function () {
    $user = User::factory()->create();
    $show = airingShow('Severance');
    watchPilot($user, $show);
    app(SetFollow::class)->execute($user, $show, FollowMode::Follow);
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());

    expect(carriedTitles($user))->toBe(['Severance']);

    setToggles($user, ['calendar_dropped' => false]);

    expect(carriedTitles($user))->toBe([]);
});

test('dropped outranks the backlog, for a series and a game alike', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $show = airingShow('Severance');
    watchPilot($user, $show);
    putOnBacklog($user, $show);
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());
    $game = MediaItem::factory()->game()->create(['title' => 'Silksong', 'release_date' => today()->addDays(9)->toDateString()]);
    putOnBacklog($user, $game);
    app(DropAttempt::class)->execute($user, $game, WatchedAt::releaseDateOrNow());

    expect(carriedTitles($user))->toBe(['Severance', 'Silksong']);

    setToggles($user, ['calendar_dropped' => false]);

    expect(carriedTitles($user))->toBe([]);
});

test('a drop speaks only for the attempt it closed: a rewatch or a fresh playthrough is history again', function () {
    $user = User::factory()->create();
    $show = airingShow('Severance');
    watchPilot($user, $show);
    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());
    app(StartRewatch::class)->execute($user, $show);
    watchPilot($user, $show);
    $film = MediaItem::factory()->movie()->create(['title' => 'Tenet', 'release_date' => '2020-08-26']);
    app(DropAttempt::class)->execute($user, $film, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, $film, WatchedAt::at(CarbonImmutable::parse('2026-02-01')), null, AttemptPolicy::NewAttempt);
    $source = fn (MediaItem $title): ?string => app(LibraryStates::class)->resolveItem($user, $title->refresh())->followSource?->value;

    expect($source($show))->toBe('activity')
        ->and($source($film))->toBe('activity')
        ->and(carriedTitles($user, Surface::Notifications))->toBe(['Severance']);
});

test('a session logged on a dropped game opens a new attempt: the game is history again, as its status card says', function () {
    enableGamesModule();
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create(['title' => 'Hades']);
    app(DropAttempt::class)->execute($user, $game, WatchedAt::at(CarbonImmutable::parse('2026-03-02')), 120);
    // What the API and an import do: a session, no clearing of the drop first.
    app(LogProgress::class)->execute($user, $game, 30, null);

    expect(app(LibraryStates::class)->resolveItem($user, $game)->followSource?->value)->toBe('activity')
        ->and(app(TrackedAudience::class)->usersFor($game)->pluck('id')->all())->toBe([$user->id]);
});

test('on hold outranks the backlog', function () {
    $user = User::factory()->create();
    $show = airingShow('Andor');
    watchPilot($user, $show);
    putOnBacklog($user, $show);
    app(SetMediaStatus::class)->execute($user, $show, MediaUserStatus::OnHold);

    setToggles($user, ['calendar_on_hold' => false]);

    expect(carriedTitles($user))->toBe([]);
});

test('a watched title on the backlog answers to the backlog toggle, not to history', function () {
    $user = User::factory()->create();
    $show = airingShow('Severance');
    watchPilot($user, $show);
    putOnBacklog($user, $show);

    setToggles($user, ['calendar_active' => false]);
    expect(carriedTitles($user))->toBe(['Severance']);

    setToggles($user, ['calendar_watchlist' => false]);
    expect(carriedTitles($user))->toBe([]);
});

test('any activity counts as history, not just a watched episode', function () {
    $user = User::factory()->create();
    Rating::factory()->for($user)->create(['media_item_id' => airingShow('Rated')->id]);
    app(SetFavorite::class)->execute($user, airingShow('Loved'), true);
    app(SetMediaStatus::class)->execute($user, airingShow('Started'), MediaUserStatus::InProgress);

    expect(carriedTitles($user))->toBe(['Loved', 'Rated', 'Started']);

    setToggles($user, ['calendar_active' => false]);

    expect(carriedTitles($user))->toBe([]);
});

test('on hold without a watched episode still answers to the on-hold toggle', function () {
    $user = User::factory()->create();
    app(SetMediaStatus::class)->execute($user, airingShow('Andor'), MediaUserStatus::OnHold);

    expect(carriedTitles($user))->toBe(['Andor']);

    setToggles($user, ['calendar_on_hold' => false]);

    expect(carriedTitles($user))->toBe([]);
});

test('a season or an episode on the backlog brings the whole series', function (string $part) {
    $user = User::factory()->create();
    $show = makeShowTree([
        1 => [today()->addDays(3)->toDateString()],
        2 => [today()->addDays(40)->toDateString()],
    ]);
    $season = $show->children()->where('number', 2)->firstOrFail();
    putOnBacklog($user, $part === 'season' ? $season : $season->children()->firstOrFail());

    $seasons = app(ReleaseFeed::class)
        ->entries($user, Surface::Calendar, today()->toDateString(), today()->addDays(60)->toDateString())
        ->map(fn (ReleaseEntry $entry): ?int => $entry->media->seasonNumber)
        ->all();

    expect($seasons)->toBe([1, 2]);
})->with(['season', 'episode']);

test("a creator's bell has its own row, your own bell keeps its", function () {
    $user = User::factory()->create();
    $mine = MediaItem::factory()->movie()->create(['title' => 'Dune: Part Three', 'release_date' => today()->addDays(10)->toDateString()]);
    app(SetFollow::class)->execute($user, $mine, FollowMode::Follow);
    $theirs = MediaItem::factory()->movie()->create(['title' => 'John Wick 5', 'release_date' => today()->addDays(20)->toDateString()]);
    Follow::factory()->for($user)->create(['media_item_id' => $theirs->id])->viaPeople()->attach(Person::factory()->create());

    expect(carriedTitles($user))->toBe(['Dune: Part Three', 'John Wick 5']);

    setToggles($user, ['calendar_creators' => false]);
    expect(carriedTitles($user))->toBe(['Dune: Part Three']);

    setToggles($user, ['calendar_followed' => false]);
    expect(carriedTitles($user))->toBe(['John Wick 5']);
});

test('a card names the creators behind a title only when they are why it is there', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['name' => 'Keanu Reeves']);
    $theirs = MediaItem::factory()->movie()->create(['title' => 'John Wick 5', 'release_date' => today()->addDays(20)->toDateString()]);
    $alsoMine = MediaItem::factory()->movie()->create(['title' => 'Constantine 2', 'release_date' => today()->addDays(30)->toDateString()]);

    foreach ([$theirs, $alsoMine] as $film) {
        Follow::factory()->for($user)->create(['media_item_id' => $film->id])->viaPeople()->attach($person);
    }

    putOnBacklog($user, $alsoMine);

    $via = app(ReleaseFeed::class)
        ->entries($user, Surface::Calendar, today()->toDateString(), today()->addDays(60)->toDateString())
        ->mapWithKeys(fn (ReleaseEntry $entry): array => [$entry->media->title => $entry->media->extra['followedVia'] ?? null])
        ->all();

    expect($via)->toBe(['John Wick 5' => 'Keanu Reeves', 'Constantine 2' => null]);
});

test('a change to a title reaches exactly the users whose daily pings carry it', function () {
    $show = airingShow('The Bear');

    $seasonOnBacklog = User::factory()->create();
    putOnBacklog($seasonOnBacklog, $show->children()->firstOrFail());

    $watcherWithBellOff = User::factory()->create(['preferences' => ['notifications_followed' => false]]);
    watchPilot($watcherWithBellOff, $show);
    app(SetFollow::class)->execute($watcherWithBellOff, $show, FollowMode::Follow);

    $ratedOnly = User::factory()->create();
    Rating::factory()->for($ratedOnly)->create(['media_item_id' => $show->id]);

    $lovedOnly = User::factory()->create();
    app(SetFavorite::class)->execute($lovedOnly, $show, true);

    $droppedQuietly = User::factory()->create();
    watchPilot($droppedQuietly, $show);
    putOnBacklog($droppedQuietly, $show);
    app(DropAttempt::class)->execute($droppedQuietly, $show, WatchedAt::releaseDateOrNow());

    $audience = app(TrackedAudience::class)->usersFor($show)->pluck('id')->sort()->values()->all();
    $pinged = collect([$seasonOnBacklog, $watcherWithBellOff, $ratedOnly, $lovedOnly, $droppedQuietly])
        ->filter(fn (User $user): bool => carriedTitles($user, Surface::Notifications) !== [])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($audience)->toBe([$seasonOnBacklog->id, $watcherWithBellOff->id, $ratedOnly->id, $lovedOnly->id])
        ->and($pinged)->toBe($audience);
});

test('the bell names the row the calendar reads the title through', function () {
    $user = User::factory()->create();
    $show = airingShow('Severance');
    app(SetFollow::class)->execute($user, $show, FollowMode::Follow);
    $source = fn (): ?string => app(LibraryStates::class)->resolveItem($user, $show->refresh())->followSource?->value;

    expect($source())->toBe('followed');

    watchPilot($user, $show);
    expect($source())->toBe('activity');

    putOnBacklog($user, $show);
    expect($source())->toBe('backlog');

    app(DropAttempt::class)->execute($user, $show, WatchedAt::releaseDateOrNow());
    expect($source())->toBe('dropped');

    $theirs = airingShow('Andor');
    Follow::factory()->for($user)->create(['media_item_id' => $theirs->id])->viaPeople()->attach(Person::factory()->create());
    expect(app(LibraryStates::class)->resolveItem($user, $theirs)->followSource?->value)->toBe('creator');

    // A season carries no bell of its own.
    expect(app(LibraryStates::class)->resolveItem($user, $show->children()->firstOrFail())->followSource?->value)->toBeNull();
});

test('a kind of title with sources of its own follows them on each surface, the others keep the defaults', function () {
    $user = User::factory()->create();
    $show = airingShow('Slow Horses');
    $film = MediaItem::factory()->movie()->create(['title' => 'Dune: Part Three', 'release_date' => today()->addDays(10)->toDateString()]);
    putOnBacklog($user, $show);
    putOnBacklog($user, $film);

    setToggles($user, ['type_sources' => ['movie' => [
        'calendar' => ['activity'],
        'countdowns' => ['backlog'],
        'notifications' => [],
    ]]]);

    expect(carriedTitles($user))->toBe(['Slow Horses'])
        ->and(carriedTitles($user, Surface::Countdowns))->toBe(['Dune: Part Three', 'Slow Horses'])
        ->and(carriedTitles($user, Surface::Notifications))->toBe(['Slow Horses'])
        ->and(app(TrackedAudience::class)->usersFor($film))->toBeEmpty()
        ->and(app(TrackedAudience::class)->usersFor($show)->pluck('id')->all())->toBe([$user->id]);
});

test('sources of its own are the whole say for a kind of title: one the defaults leave out comes back', function () {
    $user = User::factory()->create();
    $film = MediaItem::factory()->movie()->create(['title' => 'Nosferatu', 'release_date' => today()->addDays(10)->toDateString()]);
    putOnBacklog($user, $film);
    app(DropAttempt::class)->execute($user, $film, WatchedAt::releaseDateOrNow());

    setToggles($user, ['notifications_dropped' => false]);
    expect(carriedTitles($user, Surface::Notifications))->toBe([]);

    setToggles($user, ['notifications_dropped' => false, 'type_sources' => ['movie' => ['notifications' => ['dropped']]]]);
    expect(carriedTitles($user, Surface::Notifications))->toBe(['Nosferatu'])
        ->and(app(TrackedAudience::class)->usersFor($film)->pluck('id')->all())->toBe([$user->id]);
});

test("the API and the assistants' tools read a kind of title's own sources as the pages do", function () {
    $user = User::factory()->create(['preferences' => [
        'calendar_watchlist' => false,
        'countdown_watchlist' => false,
        'type_sources' => ['movie' => ['calendar' => ['backlog'], 'countdowns' => ['backlog']]],
    ]]);
    $show = airingShow('Slow Horses');
    $film = MediaItem::factory()->movie()->create(['title' => 'Dune: Part Three', 'release_date' => today()->addDays(3)->toDateString()]);
    putOnBacklog($user, $show);
    putOnBacklog($user, $film);
    Sanctum::actingAs($user, ['read']);

    $from = today()->toDateString();
    $tool = fn (string $name, array $arguments): string => (string) $this->postJson(route('api.mcp'), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
    ])->assertOk()->getContent();

    expect((string) $this->getJson(route('api.v1.calendar', ['from' => $from, 'to' => today()->addDays(10)->toDateString()]))->assertOk()->getContent())
        ->toContain('Dune: Part Three')->not->toContain('Slow Horses')
        ->and((string) $this->getJson(route('api.v1.countdowns'))->assertOk()->getContent())
        ->toContain('Dune: Part Three')->not->toContain('Slow Horses')
        ->and($tool('get_calendar', ['from' => $from, 'days' => 11]))->toContain('Dune: Part Three')->not->toContain('Slow Horses')
        ->and($tool('get_countdowns', []))->toContain('Dune: Part Three')->not->toContain('Slow Horses');
});

test('several titles read at once give each user the facts one title read alone gives', function () {
    [$alex, $sam] = [User::factory()->create(), User::factory()->create()];
    [$film, $game, $untouched] = [MediaItem::factory()->movie()->create(), MediaItem::factory()->game()->create(), MediaItem::factory()->movie()->create()];
    app(SyncListMembership::class)->execute($alex, $film, [$alex->watchlist()->id]);
    Follow::factory()->create(['user_id' => $sam->id, 'media_item_id' => $film->id, 'mode' => FollowMode::Mute]);
    Follow::factory()->create(['user_id' => $alex->id, 'media_item_id' => $game->id])->viaPeople()->attach(Person::factory()->create());

    $query = app(TrackingFactsQuery::class);
    $alone = fn (MediaItem $root): array => array_map(fn (TrackingFacts $facts): TrackingFacts => new TrackingFacts(...[...get_object_vars($facts), 'type' => null]), $query->forRoot($root));

    expect($query->forRoots([$film->id, $game->id, $untouched->id]))->toEqual([
        $film->id => $alone($film),
        $game->id => $alone($game),
    ]);
});
