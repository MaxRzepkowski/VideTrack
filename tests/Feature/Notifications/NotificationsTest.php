<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\SyncListMembership;
use App\Actions\Media\SendMediaNotification;
use App\Catalog\DatePrecision;
use App\Catalog\EpisodeBadge;
use App\Catalog\ItemMetadata;
use App\Catalog\TitleStatus;
use App\Enums\FollowMode;
use App\Jobs\NotifyTodaysReleases;
use App\Library\WatchedAt;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\TaskRun;
use App\Models\User;
use App\Notifications\MediaEvent;
use App\Notifications\MediaEventPayload;
use App\Notifications\MediaEventType;
use App\Tracking\MetadataChanges;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Surface;
use App\Tracking\Surfaces\TodaysReleases;
use App\Tracking\TrackedAudience;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Sanctum\Sanctum;

function runNotifyReleases(): void
{
    (new NotifyTodaysReleases(TaskRun::queueTask('notify-releases')))
        ->handle(app(TodaysReleases::class));
}

test('a film leaving cinemas today gets its own notification', function () {
    $user = User::factory()->create();

    // Premiered long ago — only the home release lands today, which is the
    // whole point: the film was unwatchable at home until now.
    $movie = MediaItem::factory()->movie()->create([
        'release_date' => today()->subMonths(2)->toDateString(),
        'home_release_date' => today()->toDateString(),
    ]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    $notification = DatabaseNotification::query()->where('notifiable_id', $user->id)->sole();

    expect($notification->data['event'])->toBe('home_release')
        // Its own key prefix, so it cannot collide with the premiere ping.
        ->and($notification->data['key'])->toBe("home-release:movie:{$movie->provider_ref}:".today()->toDateString());
});

test('the cinema release mode silences the home-release ping', function () {
    $user = User::factory()->create();
    $user->preferences = ['notifications_release_mode' => 'cinema'];
    $user->save();

    $movie = MediaItem::factory()->movie()->create([
        'release_date' => today()->subMonths(2)->toDateString(),
        'home_release_date' => today()->toDateString(),
    ]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    expect(DatabaseNotification::query()->where('notifiable_id', $user->id)->count())->toBe(0);
});

test('neither of a film\'s dates pings when both are switched off, while its other surfaces keep them', function () {
    $user = User::factory()->create(['preferences' => ['notifications_release_mode' => 'none']]);

    $movie = MediaItem::factory()->movie()->create([
        'release_date' => today()->toDateString(),
        'home_release_date' => today()->addDay()->toDateString(),
    ]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    expect($user->notifications()->count())->toBe(0)
        ->and(app(ReleaseFeed::class)->entries($user->refresh(), Surface::Calendar, today()->toDateString(), today()->addDay()->toDateString()))->toHaveCount(2);
});

test("the daily task follows each kind of title's own sources, the others the defaults", function (array $own, array $events) {
    $user = User::factory()->create(['preferences' => ['type_sources' => $own]]);

    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    $show = makeShowTree([1 => ['2020-01-01', today()->toDateString()]]);
    app(MarkWatched::class)->execute($user, $show->children()->firstOrFail()->children()->where('number', 1)->firstOrFail(), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    runNotifyReleases();

    expect($user->notifications()->get()->map(fn (DatabaseNotification $n) => $n->data['event'])->sort()->values()->all())->toBe($events);
})->with([
    'none of their own' => [[], ['episode_aired', 'premiere']],
    'films without the backlog' => [['movie' => ['notifications' => ['activity']]], ['episode_aired']],
    'shows without history' => [['show' => ['notifications' => ['backlog']]], ['premiere']],
    'films with views of their own only' => [['movie' => ['calendar' => [], 'countdowns' => []]], ['episode_aired', 'premiere']],
    'both with nothing' => [['movie' => ['notifications' => []], 'show' => ['notifications' => []]], []],
]);

test("the day's pings of a film follow its release mode and the premieres switch", function (string $mode, bool $premieres, bool $sameDay, array $events) {
    $user = User::factory()->create(['preferences' => [
        'notifications_release_mode' => $mode,
        'notify_premieres' => $premieres,
        // The views' own modes say nothing about the pings.
        'calendar_release_mode' => 'none',
        'countdown_release_mode' => 'cinema',
    ]]);
    $movie = MediaItem::factory()->movie()->create([
        'release_date' => $sameDay ? today()->toDateString() : today()->subMonths(2)->toDateString(),
        'home_release_date' => today()->toDateString(),
    ]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    expect($user->notifications()->get()->map(fn (DatabaseNotification $n) => $n->data['event'])->sort()->values()->all())->toBe($events);
})->with([
    'both, premieres on, same day' => ['both', true, true, ['premiere']],
    'both, premieres off, same day' => ['both', false, true, ['home_release']],
    'cinema, premieres off, same day' => ['cinema', false, true, []],
    'cinema, premieres on, home only' => ['cinema', true, false, []],
    'digital, premieres off, same day' => ['digital', false, true, ['home_release']],
    'digital, premieres on, home only' => ['digital', true, false, ['home_release']],
    'none, premieres on, same day' => ['none', true, true, []],
    'none, premieres on, home only' => ['none', true, false, []],
]);

test('with premieres off, three films out today ping, run twice, only for what comes home', function (string $mode, array $events) {
    $user = User::factory()->create(['preferences' => ['notify_premieres' => false, 'notifications_release_mode' => $mode]]);

    // Opening and coming home today, opening today, coming home today.
    foreach ([[0, 0], [0, 60], [-60, 0]] as [$premiere, $home]) {
        $film = MediaItem::factory()->movie()->create([
            'release_date' => today()->addDays($premiere)->toDateString(),
            'home_release_date' => today()->addDays($home)->toDateString(),
        ]);
        app(SyncListMembership::class)->execute($user, $film, [$user->watchlist()->id]);
    }

    runNotifyReleases();
    runNotifyReleases();

    expect($user->notifications()->get()->map(fn (DatabaseNotification $n) => $n->data['event'])->sort()->values()->all())->toBe($events);
})->with([
    'both' => ['both', ['home_release', 'home_release']],
    'cinema' => ['cinema', []],
    'digital' => ['digital', ['home_release', 'home_release']],
    'none' => ['none', []],
]);

test('home releases ping even with premiere notifications switched off', function () {
    $user = User::factory()->create();
    // Someone who never goes to cinemas: premieres are noise, the day the film
    // becomes watchable is not.
    $user->preferences = ['notify_premieres' => false, 'notifications_release_mode' => 'digital'];
    $user->save();

    $movie = MediaItem::factory()->movie()->create([
        'release_date' => today()->toDateString(),
        'home_release_date' => today()->toDateString(),
    ]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    expect(DatabaseNotification::query()->where('notifiable_id', $user->id)->sole()->data['event'])->toBe('home_release');
});

test('a film opening in cinemas and at home on one day still pings a viewer who wants only home releases', function () {
    $user = User::factory()->create();
    // Premieres switched off, the release mode left on both: the same-day
    // release arrives as one entry, and its home half is still wanted.
    $user->preferences = ['notify_premieres' => false];
    $user->save();

    $movie = MediaItem::factory()->movie()->create([
        'release_date' => today()->toDateString(),
        'home_release_date' => today()->toDateString(),
    ]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    expect(DatabaseNotification::query()->where('notifiable_id', $user->id)->sole()->data['event'])->toBe('home_release');
});

test('the first episode of the specials is an aired episode, not a season premiere', function () {
    $user = User::factory()->create();
    $show = makeShowTree([0 => [today()->toDateString()], 1 => ['2020-01-01']]);
    app(MarkWatched::class)->execute($user, MediaItem::query()->where('type', 'episode')->whereIn('parent_id', $show->children()->where('number', 1)->pluck('id'))->sole(), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    runNotifyReleases();

    $events = DatabaseNotification::query()->where('notifiable_id', $user->id)->get()->pluck('data.event')->all();

    expect($events)->toBe(['episode_aired']);
});

test('the daily task notifies about a backlog premiere and an aired episode', function () {
    $user = User::factory()->create();

    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    // A watched show with episode 2 airing today (episode 1 seen).
    $show = makeShowTree([1 => ['2020-01-01', today()->toDateString()]]);
    app(MarkWatched::class)->execute(
        $user,
        $show->children()->firstOrFail()->children()->where('number', 1)->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-01-01')),
    );

    runNotifyReleases();

    $events = $user->notifications()->get()->map(fn (DatabaseNotification $n) => $n->data['event'])->sort()->values()->all();

    expect($events)->toBe(['episode_aired', 'premiere']);

    // A catch-up re-run must not double the day.
    runNotifyReleases();

    expect($user->notifications()->count())->toBe(2);
});

test('the episode its provider marks as opening a season arrives as a season premiere, whatever its number', function () {
    $user = User::factory()->create();
    // Numbering that runs on across seasons: season 2 opens on episode 11.
    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 2)->create();
    MediaItem::factory()->episode($season, 10, '2020-01-01')->create();
    $opener = MediaItem::factory()->episode($season, 11, today()->toDateString())
        ->create(['metadata' => (new ItemMetadata(episodeBadge: EpisodeBadge::SeasonPremiere))->toStored()]);

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'mode' => FollowMode::Follow]);

    runNotifyReleases();

    $notification = $user->notifications()->sole();

    expect($notification->data['event'])->toBe('season_premiere')
        ->and($notification->data['media']['ref'])->toBe($opener->provider_ref);
});

test("a show's episodes out on one day arrive as one notification listing them", function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create(['title' => 'Crystal Lake', 'poster_path' => '/crystal-lake.jpg']);
    $season = MediaItem::factory()->season($show, 1)->create();
    $badge = fn (EpisodeBadge $badge): array => ['metadata' => (new ItemMetadata(episodeBadge: $badge))->toStored()];
    MediaItem::factory()->episode($season, 1, today()->toDateString())->create($badge(EpisodeBadge::SeriesPremiere));
    MediaItem::factory()->episode($season, 2, today()->toDateString())->create();
    MediaItem::factory()->episode($season, 3, today()->toDateString())->create($badge(EpisodeBadge::SeasonFinale));

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'mode' => FollowMode::Follow]);

    runNotifyReleases();

    $notification = $user->notifications()->sole();

    expect($notification->data['event'])->toBe('season_premiere')
        ->and($notification->data['showTitle'])->toBe('Crystal Lake')
        ->and(array_column($notification->data['items'], 'number'))->toBe([1, 2, 3])
        ->and($notification->data['key'])->toStartWith("episodes_released:{$show->id}:".today()->toDateString().':');

    // A catch-up re-run says nothing new.
    runNotifyReleases();

    expect($user->notifications()->count())->toBe(1);

    // Its page lists the episodes.
    $this->actingAs($user)
        ->get(route('notifications.show', $notification->id))
        ->assertInertia(fn ($page) => $page->component('NotificationDetails')
            ->has('items', 3)
            // Each as the history shows it: under the show's poster and name.
            ->where('items.0.media.posterPath', '/crystal-lake.jpg')
            ->where('items.0.media.extra.showTitle', 'Crystal Lake')
            ->where('items.2.media.extra.seasonNumber', 1)
            ->where('items.0.available', true));
});

test('a second run the same day says only the episodes not said yet', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 1)->create();
    $today = today()->toDateString();
    MediaItem::factory()->episode($season, 1, $today)->create();
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'mode' => FollowMode::Follow]);

    runNotifyReleases();

    // The provider dates two more episodes today after the run.
    MediaItem::factory()->episode($season, 2, $today)->create();
    MediaItem::factory()->episode($season, 3, $today)->create();
    refreshTitle($show);

    runNotifyReleases();

    // One more for the pair; the first episode is not listed again.
    MediaItem::factory()->episode($season, 4, $today)->create();
    refreshTitle($show);

    runNotifyReleases();

    $said = $user->notifications()->get()
        ->map(fn (DatabaseNotification $n): array => array_column($n->data['items'] ?? [$n->data['media']], 'number'))
        ->sortBy(fn (array $numbers): int => $numbers[0])
        ->values()
        ->all();

    expect($said)->toBe([[1], [2, 3], [4]]);
});

test('a new title a creator and a followed collection both announce is one notification, signed by the first', function () {
    $user = User::factory()->create();
    $film = MediaItem::factory()->movie()->create(['release_date' => today()->addMonth()->toDateString()]);
    $saga = MediaList::factory()->collection('tmdb', '2344')->create(['title' => 'The Saga']);
    $send = app(SendMediaNotification::class);

    $send->execute($user, MediaEventPayload::creatorTitle(MediaEventType::PersonNewTitle, $film, 'Denis'));
    $send->execute($user, MediaEventPayload::creatorTitle(MediaEventType::CompanyNewTitle, $film, 'A24'));
    $send->execute($user, MediaEventPayload::collectionParts($saga, [$film]));

    expect($user->notifications()->sole()->data)->toMatchArray(['event' => 'person_new_title', 'personName' => 'Denis']);
});

test('mute and the event-kind toggles silence the daily task', function () {
    $muter = User::factory()->create();
    $optedOut = User::factory()->create(['preferences' => ['notify_premieres' => false]] + []);

    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);

    foreach ([$muter, $optedOut] as $user) {
        app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);
    }

    Follow::factory()->create(['user_id' => $muter->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Mute]);

    runNotifyReleases();

    expect($muter->notifications()->count())->toBe(0)
        ->and($optedOut->notifications()->count())->toBe(0);
});

test('metadata diffs detect a moved date, a status change and a new season', function () {
    $detect = app(MetadataChanges::class);

    $show = makeShowTree([1 => ['2020-01-01']]);
    $show->update(['status' => TitleStatus::ReturningSeries, 'release_date' => '2020-01-01']);

    $before = $detect->snapshot($show->refresh());

    // The refresh lands: new date, ended status, a second season appears.
    $show->update(['release_date' => '2027-01-01', 'status' => TitleStatus::Ended]);
    MediaItem::factory()->season($show, 2)->create();

    $events = collect($detect->diff($show->refresh(), $before))->keyBy(fn (MediaEventPayload $event): string => $event->type->value);

    expect($events->keys()->sort()->values()->all())->toBe(['date_changed', 'season_announced', 'show_status'])
        ->and($events['date_changed']->oldDate)->toBe('2020-01-01')
        ->and($events['show_status']->status)->toBe('ended')
        ->and($events['season_announced']->season)->toBe(2);
});

test('a new season takes in the show coming back from the same refresh, not a date or another status', function () {
    $detect = app(MetadataChanges::class);

    $show = makeShowTree([1 => ['2020-01-01']]);
    $show->update(['status' => TitleStatus::Ended]);
    $before = $detect->snapshot($show->refresh());

    // Renewed: back to running, a second season listed.
    $show->update(['status' => TitleStatus::ReturningSeries]);
    MediaItem::factory()->season($show, 2)->create();

    expect(array_map(fn (MediaEventPayload $event): ?MediaEventType => $event->type, $detect->diff($show->refresh(), $before)))
        ->toBe([MediaEventType::SeasonAnnounced]);

    // The status alone, with no season, is still said.
    $quiet = makeShowTree([1 => ['2020-01-01']]);
    $quiet->update(['status' => TitleStatus::Ended]);
    $quietBefore = $detect->snapshot($quiet->refresh());
    $quiet->update(['status' => TitleStatus::ReturningSeries]);

    expect(array_map(fn (MediaEventPayload $event): ?MediaEventType => $event->type, $detect->diff($quiet->refresh(), $quietBefore)))
        ->toBe([MediaEventType::ShowStatus]);

    // A new season beside a status that says anything else, and a moved date,
    // stay their own news.
    $cut = makeShowTree([1 => ['2020-01-01']]);
    $cut->update(['status' => TitleStatus::ReturningSeries, 'release_date' => '2020-01-01']);
    $cutBefore = $detect->snapshot($cut->refresh());
    $cut->update(['status' => TitleStatus::Canceled, 'release_date' => today()->addMonth()->toDateString()]);
    MediaItem::factory()->season($cut, 2)->create();

    expect(array_map(fn (MediaEventPayload $event): ?MediaEventType => $event->type, $detect->diff($cut->refresh(), $cutBefore)))
        ->toBe([MediaEventType::DateChanged, MediaEventType::ShowStatus, MediaEventType::SeasonAnnounced]);
});

test('a date correction on something already out is not an announcement', function () {
    $detect = app(MetadataChanges::class);

    $movie = MediaItem::factory()->movie()->create(['release_date' => '2014-03-01']);

    $before = $detect->snapshot($movie->refresh());

    // The provider tidies up the release date of a film from years ago.
    $movie->update(['release_date' => '2014-03-05']);

    expect($detect->diff($movie->refresh(), $before))->toBe([]);

    // The same move, but landing ahead of today, is real news.
    $movie->update(['release_date' => today()->addMonth()->toDateString()]);

    expect(array_map(fn (MediaEventPayload $event): MediaEventType => $event->type, $detect->diff($movie->refresh(), $before)))->toBe([MediaEventType::DateChanged]);
});

test('a release moved onto today still announces itself', function () {
    $detect = app(MetadataChanges::class);

    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->addYear()->toDateString()]);
    $before = $detect->snapshot($movie->refresh());

    $movie->update(['release_date' => today()->toDateString()]);

    expect(array_map(fn (MediaEventPayload $event): MediaEventType => $event->type, $detect->diff($movie->refresh(), $before)))->toBe([MediaEventType::DateChanged]);
});

test('a date known only to its year that gains its day is news, the same stand-in again is not', function () {
    enableGamesModule();
    $detect = app(MetadataChanges::class);

    $game = MediaItem::factory()->game()->create(['release_date' => '2028-12-31', 'release_date_precision' => DatePrecision::Year]);
    $before = $detect->snapshot($game->refresh());

    $game->update(['release_date' => '2028-12-31']);

    expect($detect->diff($game->refresh(), $before))->toBe([]);

    // Stored as an exact day, then read again as the year it is: no news.
    $misread = MediaItem::factory()->game()->create(['release_date' => '2027-12-31']);
    $misreadBefore = $detect->snapshot($misread->refresh());
    $misread->update(['release_date_precision' => DatePrecision::Year]);

    expect($detect->diff($misread->refresh(), $misreadBefore))->toBe([]);

    // IGDB names the day: the very day it had stamped "2028" with, even.
    $game->update(['release_date_precision' => DatePrecision::Day]);
    $events = $detect->diff($game->refresh(), $before);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(MediaEventType::DateChanged)
        ->and($events[0]->oldDatePrecision)->toBe(DatePrecision::Year)
        ->and($events[0]->key)->toBe("date_changed:game:{$game->provider_ref}:2028-12-31")
        ->and(MediaEventPayload::fromStored($events[0]->toStored())->oldDatePrecision)->toBe(DatePrecision::Year);
});

test('a year announced for a game keys apart from that exact day', function () {
    enableGamesModule();
    $game = MediaItem::factory()->game()->create(['release_date' => '2028-12-31', 'release_date_precision' => DatePrecision::Year]);

    $event = MediaEventPayload::dateChanged($game, null, DatePrecision::Day);

    expect($event->key)->toBe("date_changed:game:{$game->provider_ref}:2028-12-31:year")
        ->and($event->datePrecision())->toBe(DatePrecision::Year)
        ->and($event->oldDatePrecision)->toBeNull();
});

test('an approximate premiere is not "out today" on its stand-in day', function () {
    enableGamesModule();
    $this->travelTo(CarbonImmutable::parse('2026-12-31 08:00'));
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create(['release_date' => '2026-12-31', 'release_date_precision' => DatePrecision::Year, 'metadata' => null]);
    app(SyncListMembership::class)->execute($user, $game, [$user->watchlist()->id]);

    runNotifyReleases();

    expect($user->notifications()->count())->toBe(0);
});

test('the tracked audience reads the matrix', function () {
    $movie = MediaItem::factory()->movie()->create();

    $viaBacklog = User::factory()->create();
    app(SyncListMembership::class)->execute($viaBacklog, $movie, [$viaBacklog->watchlist()->id]);

    $muted = User::factory()->create();
    app(SyncListMembership::class)->execute($muted, $movie, [$muted->watchlist()->id]);
    Follow::factory()->create(['user_id' => $muted->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Mute]);

    $follower = User::factory()->create();
    Follow::factory()->create(['user_id' => $follower->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Follow]);

    $stranger = User::factory()->create();

    $audience = app(TrackedAudience::class)->usersFor($movie)->pluck('id');

    expect($audience->all())->toContain($viaBacklog->id, $follower->id)
        ->not->toContain($muted->id, $stranger->id);
});

test('the notifications page, single read and read-all work', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    $notification = $user->notifications()->firstOrFail();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Notifications')
            ->has('notifications', 1)
            ->where('notifications.0.readAt', null));

    // The shared header prop carries the unread counter.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('notifications.unread', 1));

    $this->actingAs($user)
        ->patch(route('ui.notifications.read', ['notification' => $notification->id]))
        ->assertNoContent();

    expect($notification->refresh()->read_at)->not->toBeNull();

    runNotifyReleases(); // nothing new — still deduped

    $this->actingAs($user)
        ->post(route('ui.notifications.read-all'))
        ->assertNoContent();

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('a notification can be deleted, but only from its own feed', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);
    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    $notification = $user->notifications()->firstOrFail();

    // Someone else's id is invisible — 404, nothing deleted.
    $this->actingAs(User::factory()->create())
        ->delete(route('notifications.destroy', ['notification' => $notification->id]))
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('notifications.destroy', ['notification' => $notification->id]))
        ->assertRedirect();

    expect($user->notifications()->count())->toBe(0);
});

test('delete-all clears only the acting user\'s feed', function () {
    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);

    $user = User::factory()->create();
    $other = User::factory()->create();

    foreach ([$user, $other] as $tracker) {
        app(SyncListMembership::class)->execute($tracker, $movie, [$tracker->watchlist()->id]);
    }

    runNotifyReleases();

    $this->actingAs($user)
        ->delete(route('notifications.destroy-all'))
        ->assertRedirect();

    expect($user->notifications()->count())->toBe(0)
        ->and($other->notifications()->count())->toBe(1);
});

test('the bell feed answers as JSON, scoped to the acting user', function () {
    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->toDateString()]);

    $user = User::factory()->create();
    $stranger = User::factory()->create();

    app(SyncListMembership::class)->execute($user, $movie, [$user->watchlist()->id]);

    runNotifyReleases();

    $this->getJson(route('notifications.feed'))->assertUnauthorized();

    // What the header polls between navigations: the same shape as the shared
    // prop, so a fresh answer can replace it in place.
    $this->actingAs($user)
        ->getJson(route('notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonCount(1, 'recent')
        ->assertJsonPath('recent.0.readAt', null)
        ->assertJsonPath('recent.0.data.media.title', $movie->title);

    $this->actingAs($stranger)
        ->getJson(route('notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread', 0)
        ->assertJsonCount(0, 'recent');
});

test('the same event cannot be written twice for one user, even past the check', function () {
    $user = User::factory()->create();
    $user->notify(new MediaEvent(premiereEvent()));

    expect(fn () => $user->notify(new MediaEvent(premiereEvent())))->toThrow(UniqueConstraintViolationException::class)
        ->and(app(SendMediaNotification::class)->execute($user, premiereEvent()))->toBeFalse()
        ->and($user->notifications()->count())->toBe(1)
        // Another user takes the same event for themselves.
        ->and(app(SendMediaNotification::class)->execute(User::factory()->create(), premiereEvent()))->toBeTrue();
});

test('the bell, the notifications page and the API carry the copy, worded in the language of the request', function () {
    $user = User::factory()->create(['locale' => 'pl']);
    app(SendMediaNotification::class)->execute($user, premiereEvent('Diuna', '438631'));
    $movie = route('movie.details', ['ref' => '438631']);

    $this->actingAs($user)
        ->getJson(route('notifications.feed'))
        ->assertJsonPath('recent.0.event', 'premiere')
        ->assertJsonPath('recent.0.title', 'Dziś premiera: Diuna')
        ->assertJsonPath('recent.0.detail', [])
        ->assertJsonPath('recent.0.href', $movie)
        ->assertJsonPath('recent.0.mediaTitle', 'Diuna');

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn ($page) => $page->where('notifications.0.title', 'Dziś premiera: Diuna'));

    Sanctum::actingAs($user->forceFill(['locale' => 'en']), ['read']);

    $this->getJson('/api/v1/notifications')
        ->assertJsonPath('data.0.title', 'Out today: Diuna')
        ->assertJsonPath('data.0.data.event', 'premiere');
});

test('a notification that lists several titles opens on its own page', function () {
    $user = User::factory()->create();
    app(SendMediaNotification::class)->execute($user, MediaEventPayload::titlesRemoved([MediaItem::factory()->movie()->create(), MediaItem::factory()->movie()->create()]));
    $id = $user->notifications()->firstOrFail()->id;

    $this->actingAs($user)
        ->getJson(route('notifications.feed'))
        ->assertJsonPath('recent.0.href', route('notifications.show', ['notification' => $id]));
});

test('notifications written in the same second read back newest first', function () {
    $user = User::factory()->create();

    foreach (['A', 'B', 'C'] as $title) {
        app(SendMediaNotification::class)->execute($user, premiereEvent($title, $title));
    }

    expect($user->notifications()->get()->map(fn ($notification) => $notification->data['media']['title'])->all())->toBe(['C', 'B', 'A']);
});
