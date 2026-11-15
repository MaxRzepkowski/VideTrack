<?php

use App\Actions\Library\BackfillPlay;
use App\Actions\Library\CreateList;
use App\Actions\Library\DropAttempt;
use App\Actions\Library\LogProgress;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\ReorderLists;
use App\Actions\Library\SetCreatorFollow;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SetFollow;
use App\Actions\Library\SetMediaStatus;
use App\Actions\Library\StartRewatch;
use App\Actions\Library\SyncCreatorLists;
use App\Actions\Library\SyncListMembership;
use App\Actions\Scrobble\ApplyScrobbleEvent;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\MediaKind;
use App\Enums\FollowMode;
use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\DeliveryNote;
use App\Integrations\Scrobble\ScrobbleEventKind;
use App\Library\AttemptPolicy;
use App\Library\Events\PlayAmountChanged;
use App\Library\Events\ProgressLogged;
use App\Library\Events\RewatchStarted;
use App\Library\LibraryStates;
use App\Library\PlayDating;
use App\Library\Sponsorships;
use App\Library\WatchedAt;
use App\Models\Company;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\Play;
use App\Models\User;
use App\Models\WatchCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The library written through the actions and the same library restored by
 * an import must be one state: a backup that comes back different, or an
 * import that reads a story another way than a click does, is a second set
 * of rules. Each comparison is the whole state, field by field.
 */

/**
 * Everything a user's library holds, keyed by provider coordinates so two
 * accounts compare: the state each position shows, and the rows behind it.
 *
 * @param  list<MediaItem>  $items
 * @return array<string, mixed>
 */
function librarySnapshot(User $user, array $items): array
{
    $key = fn (MediaItem $item): string => "{$item->type}:{$item->provider}:{$item->provider_ref}";
    $listTitles = $user->lists()->get()->mapWithKeys(fn (MediaList $list) => [$list->id => $list->displayTitle()]);
    $library = app(LibraryStates::class);

    $states = [];

    foreach ($items as $item) {
        // As the wire carries it: the next episode is a pointer object until encoded.
        $state = json_decode((string) json_encode($library->resolveItem($user, $item->fresh() ?? $item)), true);
        $state['listIds'] = collect($state['listIds'])->map(fn (int $id) => $listTitles[$id])->sort()->values()->all();
        $states[$key($item)] = $state;
    }

    return [
        'states' => $states,
        'plays' => $user->plays()->with(['mediaItem', 'watchCycle'])->get()
            ->map(fn (Play $play) => [
                $key($play->mediaItem),
                $play->watched_at->toIso8601String(),
                $play->outcome->value,
                $play->amount === null ? null : (int) $play->amount,
                $play->cycleNumber(),
            ])
            ->sortBy(fn (array $row) => implode('|', array_map('strval', $row)))
            ->values()->all(),
        'ratings' => $user->ratings()->with('mediaItem')->get()
            ->map(fn ($rating) => [$key($rating->mediaItem), $rating->rating, $rating->updated_at?->toIso8601String()])
            ->sortBy(0)->values()->all(),
        'favorites' => $user->favorites()->with('mediaItem')->get()
            ->map(fn ($favorite) => [$key($favorite->mediaItem), $favorite->created_at?->toIso8601String()])
            ->sortBy(0)->values()->all(),
        'openAttempts' => $user->mediaStates()->with('mediaItem')->get()
            ->map(fn ($state) => [$key($state->mediaItem), $state->status->value, $state->progress, $state->created_at?->toIso8601String()])
            ->sortBy(0)->values()->all(),
        'lists' => $user->lists()->with(['items.mediaItem', 'items.person', 'items.company'])->orderBy('position')->get()
            ->map(fn (MediaList $list) => [
                'title' => $list->displayTitle(),
                'systemKey' => $list->system_key,
                'description' => $list->description,
                'isPublic' => $list->is_public,
                'items' => $list->items->map(fn (ListItem $entry) => [
                    match (true) {
                        $entry->person !== null => "person:{$entry->person->provider}:{$entry->person->provider_ref}",
                        $entry->company !== null => "{$entry->company->kind}:{$entry->company->provider}:{$entry->company->provider_ref}",
                        default => $key($entry->mediaItem),
                    },
                    $entry->created_at?->toIso8601String(),
                ])->all(),
            ])
            ->all(),
        'follows' => Follow::query()->where('user_id', $user->id)->with(['mediaItem', 'person', 'company', 'list'])->get()
            ->map(fn (Follow $follow) => [
                match (true) {
                    $follow->mediaItem !== null => $key($follow->mediaItem),
                    $follow->person !== null => "person:{$follow->person->provider}:{$follow->person->provider_ref}",
                    $follow->company !== null => "{$follow->company->kind}:{$follow->company->provider}:{$follow->company->provider_ref}",
                    default => "collection:{$follow->list?->system_key}",
                },
                $follow->mode->value,
            ])
            ->sortBy(0)->values()->all(),
        'cycles' => WatchCycle::query()->where('user_id', $user->id)->with('mediaItem')->get()
            ->map(fn (WatchCycle $cycle) => [$key($cycle->mediaItem), $cycle->number, $cycle->started_at->toIso8601String()])
            ->sortBy(fn (array $row) => "{$row[0]}|{$row[1]}")->values()->all(),
    ];
}

/**
 * @param  array<string, mixed>  $export
 */
function restoreInto(User $user, array $export): void
{
    Storage::fake('local');
    Storage::disk('local')->put('imports/backup.json', (string) json_encode($export));

    $run = runImportFile($user, 'videtrack', 'imports/backup.json');

    expect($run->status)->toBe(TaskRunStatus::Success);
}

/**
 * A library with every kind of story the attempt model tells, written the
 * way the UI writes it.
 *
 * @return list<MediaItem>
 */
function buildVariedLibrary(User $user): array
{
    $at = fn (string $moment): CarbonImmutable => CarbonImmutable::parse($moment);
    $mark = app(MarkWatched::class);
    $drop = app(DropAttempt::class);
    $progress = app(LogProgress::class);

    $twice = MediaItem::factory()->movie()->create();
    $mark->execute($user, $twice, WatchedAt::at($at('2026-01-10 21:00')));
    $mark->execute($user, $twice, WatchedAt::at($at('2026-03-01 20:00')));

    $finishedAfterDrop = MediaItem::factory()->movie()->create();
    $drop->execute($user, $finishedAfterDrop, WatchedAt::at($at('2026-02-01 20:00')));
    $mark->execute($user, $finishedAfterDrop, WatchedAt::at($at('2026-02-05 20:00')));

    $dropped = MediaItem::factory()->movie()->create();
    $drop->execute($user, $dropped, WatchedAt::at($at('2026-04-02 22:00')));

    $paused = MediaItem::factory()->game()->create();
    $progress->execute($user, $paused, 120, null);
    app(SetMediaStatus::class)->execute($user, $paused, MediaUserStatus::OnHold);

    $replayed = MediaItem::factory()->game()->create();
    $progress->execute($user, $replayed, 300, null);
    $drop->execute($user, $replayed, WatchedAt::at($at('2026-03-10 18:00')));
    $mark->execute($user, $replayed, WatchedAt::at($at('2026-04-01 18:00')), 600, AttemptPolicy::NewAttempt);

    $reading = MediaItem::factory()->book()->create();
    $progress->execute($user, $reading, null, 120);

    $rewatched = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2020-02-01', '2020-02-08']]);
    $mark->execute($user, $rewatched, WatchedAt::at($at('2026-05-01 20:00')));
    test()->travelTo($at('2026-06-01 12:00'));
    app(StartRewatch::class)->execute($user, $rewatched);
    test()->travelTo($at('2026-10-10 12:00'));
    $mark->execute($user, episodeOf($rewatched, 1, 1), WatchedAt::at($at('2026-06-02 20:00')));

    $partly = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2020-02-01', '2020-02-08'], 3 => ['2020-03-01']]);
    $mark->execute($user, episodeOf($partly, 1, 1), WatchedAt::at($at('2026-05-05 20:00')));
    $drop->execute($user, seasonOf($partly, 2), WatchedAt::at($at('2026-05-06 20:00')));

    $rate = app(RateItem::class);
    $rate->execute($user, $rewatched, 8);
    $rate->execute($user, episodeOf($rewatched, 1, 2), 9);
    $rate->execute($user, $twice, 7);

    app(SetFavorite::class)->execute($user, $twice, true);

    $wanted = MediaItem::factory()->movie()->create();
    $best = app(CreateList::class)->execute($user, 'Best of');
    app(SyncListMembership::class)->execute($user, $wanted, [$user->watchlist()->id]);
    app(SyncListMembership::class)->execute($user, $twice, [$best->id]);
    // Arranged by the user, not left in the order they were made.
    $later = app(CreateList::class)->execute($user, 'Later');
    app(ReorderLists::class)->execute($user, [$later->id, $best->id]);

    $person = Person::factory()->create();
    $studio = Company::factory()->create();
    app(SyncCreatorLists::class)->execute($user, new CreatorRef(CreatorKind::Person, $person->provider, $person->provider_ref), [$best->id], 'en');
    app(SyncCreatorLists::class)->execute($user, new CreatorRef(CreatorKind::Company, $studio->provider, $studio->provider_ref), [$best->id], 'en');

    app(SetFollow::class)->execute($user, $wanted, FollowMode::Follow);
    app(SetFollow::class)->execute($user, $partly, FollowMode::Mute);
    app(SetCreatorFollow::class)->execute($user, new CreatorRef(CreatorKind::Person, $person->provider, $person->provider_ref), FollowMode::Follow, 'en');

    return [
        $twice, $finishedAfterDrop, $dropped, $paused, $replayed, $reading,
        $rewatched, seasonOf($rewatched, 1), episodeOf($rewatched, 1, 1), episodeOf($rewatched, 1, 2),
        $partly, seasonOf($partly, 1), seasonOf($partly, 2), seasonOf($partly, 3), episodeOf($partly, 2, 1),
        $wanted,
    ];
}

beforeEach(function () {
    enableGamesModule();
    enableBooksModule();
    // A followed person's titles are fetched by a queued sync the test has no provider for.
    Queue::fake();
});

test('a backup restores into an empty account as the very library it was taken from', function () {
    $owner = User::factory()->create();
    $items = buildVariedLibrary($owner);

    $restored = User::factory()->create();
    restoreInto($restored, backupOf($owner));

    $taken = librarySnapshot($owner, $items);

    // The bells and mutes, and a rerun that began a day before its first
    // viewing, are part of what is compared.
    expect($taken['follows'])->toHaveCount(3)
        ->and(array_column($taken['cycles'], 2))->toBe(['2026-06-01T12:00:00+00:00'])
        ->and(librarySnapshot($restored, $items))->toBe($taken);
});

test('a run begun but not watched yet comes back, and a restore twice writes nothing new', function () {
    $owner = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);
    app(MarkWatched::class)->execute($owner, $show, WatchedAt::at(CarbonImmutable::parse('2026-05-01 20:00')));
    app(StartRewatch::class)->execute($owner, $show);

    $restored = User::factory()->create();
    restoreInto($restored, backupOf($owner));
    restoreInto($restored, backupOf($owner));

    expect(librarySnapshot($restored, [$show]))->toBe(librarySnapshot($owner, [$show]));
});

test('a followed collection comes back when the instance knows it, and is reported when its provider is missing', function () {
    $saga = MediaList::factory()->create(['user_id' => null, 'system_key' => MediaList::collectionKey('tmdb', '2344'), 'title' => 'The Saga', 'is_public' => true]);
    $owner = User::factory()->create();
    app(SetCreatorFollow::class)->execute($owner, new CreatorRef(CreatorKind::Collection, 'tmdb', '2344'), FollowMode::Follow, 'en');

    $restored = User::factory()->create();
    restoreInto($restored, backupOf($owner));

    expect(librarySnapshot($restored, [])['follows'])->toBe([['collection:'.$saga->system_key, 'follow']]);

    $backup = backupOf($owner);
    $backup['follows'][0] = [...$backup['follows'][0], 'provider' => 'nowhere'];
    $elsewhere = User::factory()->create();
    Storage::fake('local');
    Storage::disk('local')->put('imports/backup.json', (string) json_encode($backup));
    $run = runImportFile($elsewhere, 'videtrack', 'imports/backup.json');

    expect($run->summary)->toContain('follows: 0', 'skipped entries: 1')
        ->and($run->errors)->toBe(['The Saga: no provider for [nowhere:collection] on this instance.']);
});

test('a title muted while a followed person backs it comes back muted and backed: lifting the mute rings the person\'s bell', function () {
    Queue::fake();
    $movie = MediaItem::factory()->movie()->create();
    $person = Person::factory()->create();
    $creator = new CreatorRef(CreatorKind::Person, $person->provider, $person->provider_ref);
    // What the queued sync of the person's titles does, here and after the restore.
    $syncPerson = fn (User $user) => app(Sponsorships::class)->sponsor($user, CreatorKind::Person, $person->id, [$movie->id]);

    $owner = User::factory()->create();
    app(SetCreatorFollow::class)->execute($owner, $creator, FollowMode::Follow, 'en');
    $syncPerson($owner);
    app(SetFollow::class)->execute($owner, $movie, FollowMode::Mute);

    $restored = User::factory()->create();
    restoreInto($restored, backupOf($owner));
    $syncPerson($restored);

    foreach ([$owner, $restored] as $user) {
        app(SetFollow::class)->execute($user, $movie, null);
    }

    expect($restored->follows()->where('media_item_id', $movie->id)->value('mode'))->toBe(FollowMode::Follow)
        ->and(librarySnapshot($restored, [$movie])['follows'])->toBe(librarySnapshot($owner, [$movie])['follows']);
});

test('a restore leaves the bells the user rang here as they are', function () {
    $show = makeShowTree([1 => ['2020-01-01']]);
    $person = Person::factory()->create();
    $creator = new CreatorRef(CreatorKind::Person, $person->provider, $person->provider_ref);

    $owner = User::factory()->create();
    app(SetFollow::class)->execute($owner, $show, FollowMode::Follow);
    app(SetCreatorFollow::class)->execute($owner, $creator, FollowMode::Follow, 'en');

    $user = User::factory()->create();
    app(SetFollow::class)->execute($user, $show, FollowMode::Mute);
    app(SetCreatorFollow::class)->execute($user, $creator, FollowMode::Mute, 'en');

    Storage::fake('local');
    Storage::disk('local')->put('imports/backup.json', (string) json_encode(backupOf($owner)));
    $run = runImportFile($user, 'videtrack', 'imports/backup.json');

    expect($run->summary)->toContain('follows: 0')
        ->and(librarySnapshot($user, [$show])['follows'])->toBe([
            ["person:{$person->provider}:{$person->provider_ref}", 'mute'],
            ["show:{$show->provider}:{$show->provider_ref}", 'mute'],
        ]);
});

/**
 * The same story told by clicks to one account and by an import from another
 * service to a second one, compared position by position.
 *
 * @param  list<MediaItem>  $items
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function bothWays(array $items, Closure $clicks, array $rows): array
{
    $clicked = User::factory()->create();
    $clicks($clicked);

    $imported = User::factory()->create();
    runFakeImport($imported, $rows);

    return [librarySnapshot($clicked, $items), librarySnapshot($imported, $items)];
}

function subjectOf(MediaItem $item): MediaSubject
{
    return MediaSubject::direct($item->type, $item->provider, $item->provider_ref, $item->title);
}

test('a drop without a date lands on the release date either way', function () {
    $movie = MediaItem::factory()->movie()->create(['release_date' => '2024-05-01']);

    [$clicked, $imported] = bothWays(
        [$movie],
        fn (User $user) => app(DropAttempt::class)->execute($user, $movie, WatchedAt::releaseDateOrNow()),
        [new MediaRow(subjectOf($movie), drop: new ImportDrop)],
    );

    expect($clicked['plays'][0][1])->toBe('2024-05-01T00:00:00+00:00')
        ->and($imported)->toBe($clicked);
});

test('dropped, then finished: one entry either way when the source tells both at once', function () {
    $movie = MediaItem::factory()->movie()->create();
    $dropped = CarbonImmutable::parse('2026-02-01 20:00');
    $finished = CarbonImmutable::parse('2026-02-05 20:00');

    [$clicked, $imported] = bothWays(
        [$movie],
        function (User $user) use ($movie, $dropped, $finished) {
            app(DropAttempt::class)->execute($user, $movie, WatchedAt::at($dropped));
            app(MarkWatched::class)->execute($user, $movie, WatchedAt::at($finished));
        },
        [new MediaRow(subjectOf($movie), plays: [new ImportPlay($finished)], drop: new ImportDrop($dropped))],
    );

    expect($imported)->toBe($clicked);
});

test('dropped, then finished in a later import: the import keeps the drop as an entry of its own', function () {
    $movie = MediaItem::factory()->movie()->create();
    $dropped = CarbonImmutable::parse('2026-02-01 20:00');
    $finished = CarbonImmutable::parse('2026-02-05 20:00');

    $clicked = User::factory()->create();
    app(DropAttempt::class)->execute($clicked, $movie, WatchedAt::at($dropped));
    app(MarkWatched::class)->execute($clicked, $movie, WatchedAt::at($finished));

    $imported = User::factory()->create();
    runFakeImport($imported, [new MediaRow(subjectOf($movie), drop: new ImportDrop($dropped))]);
    runFakeImport($imported, [new MediaRow(subjectOf($movie), plays: [new ImportPlay($finished)])]);

    $clickedState = librarySnapshot($clicked, [$movie]);
    $importedState = librarySnapshot($imported, [$movie]);
    $key = "movie:{$movie->provider}:{$movie->provider_ref}";

    // Watched either way; the import's history shows the abandoned attempt
    // too, so the card still knows when it was dropped.
    expect(array_column($clickedState['plays'], 2))->toBe(['completed'])
        ->and(array_column($importedState['plays'], 2))->toBe(['dropped', 'completed'])
        ->and($clickedState['states'][$key]['droppedAt'])->toBeNull()
        ->and($importedState['states'][$key]['droppedAt'])->toBe('2026-02-01T20:00:00+00:00')
        ->and([...$importedState['states'][$key], 'droppedAt' => null])->toBe($clickedState['states'][$key]);
});

test('a rating the user already gave stands against an import, and a click replaces it', function () {
    $movie = MediaItem::factory()->movie()->create();
    $user = User::factory()->create();
    app(RateItem::class)->execute($user, $movie, 6);

    runFakeImport($user, [new MediaRow(subjectOf($movie), rating: new ImportRating(9))]);
    expect($user->ratings()->firstOrFail()->rating)->toBe(6);

    app(RateItem::class)->execute($user, $movie, 9);
    expect($user->ratings()->firstOrFail()->rating)->toBe(9);
});

test('a rating outside the scale is left out by an import, and the run says why', function () {
    $movie = MediaItem::factory()->movie()->create();
    $user = User::factory()->create();

    $run = runFakeImport($user, [new MediaRow(subjectOf($movie), rating: new ImportRating(11))]);

    expect($user->ratings()->count())->toBe(0)
        ->and($run->summary)->toContain('ratings: 0', 'skipped entries: 1')
        ->and($run->errors)->toBe(["{$movie->title}: a rating of 11 is off the 1–10 scale."]);
});

test('a drop is counted as a drop in the import summary', function () {
    $movie = MediaItem::factory()->movie()->create();

    $run = runFakeImport(User::factory()->create(), [new MediaRow(subjectOf($movie), drop: new ImportDrop(CarbonImmutable::parse('2026-02-01')))]);

    expect($run->summary)->toContain('statuses: 0', 'drops: 1');
});

test('a viewing a media server reported is one play, whichever of the webhook and the import saw it first', function () {
    $movie = MediaItem::factory()->movie()->create();
    $watched = CarbonImmutable::parse('2026-03-01 21:00');

    $webhookFirst = User::factory()->create();
    app(MarkWatched::class)->execute($webhookFirst, $movie, WatchedAt::at($watched));
    runFakeImport($webhookFirst, [new MediaRow(subjectOf($movie), plays: [new ImportPlay($watched->addHours(2), scrobbled: true)])]);

    $importFirst = User::factory()->create();
    runFakeImport($importFirst, [new MediaRow(subjectOf($movie), plays: [new ImportPlay($watched->addHours(2), scrobbled: true)])]);
    $outcome = app(ApplyScrobbleEvent::class)->execute($importFirst, $movie, new ScrobbleEvent(ScrobbleEventKind::Complete, MediaKind::Movie, [], occurredAt: $watched));

    expect($webhookFirst->plays()->count())->toBe(1)
        ->and($outcome->note)->toBe(DeliveryNote::AlreadyPlayed)
        ->and($importFirst->plays()->count())->toBe(1);
});

test('a show dropped, then watched on: a later import of the viewing lifts the drop as a click does', function () {
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $dropped = CarbonImmutable::parse('2026-02-01 20:00');
    $watched = CarbonImmutable::parse('2026-03-01 20:00');
    $items = [$show, seasonOf($show, 1), episodeOf($show, 1, 1)];

    $clicked = User::factory()->create();
    test()->travelTo($dropped);
    app(DropAttempt::class)->execute($clicked, $show, WatchedAt::at($dropped));
    test()->travelTo($watched);
    app(MarkWatched::class)->execute($clicked, episodeOf($show, 1, 1), WatchedAt::at($watched));

    // A connected service read twice: once while the show stood dropped,
    // once after the user went on with it.
    $imported = User::factory()->create();
    test()->travelTo($dropped->addHour());
    runFakeImport($imported, [new MediaRow(subjectOf($show), drop: new ImportDrop($dropped))]);
    test()->travelTo($watched->addHour());
    runFakeImport($imported, [new MediaRow(subjectOf(episodeOf($show, 1, 1)), plays: [new ImportPlay($watched)])]);
    test()->travelTo(CarbonImmutable::parse('2026-10-10 12:00'));

    $key = "show:{$show->provider}:{$show->provider_ref}";
    $clickedState = librarySnapshot($clicked, $items);

    expect($clickedState['states'][$key]['status'])->toBeNull()
        ->and($clickedState['states'][$key]['followSource'])->toBe('activity')
        ->and(librarySnapshot($imported, $items))->toBe($clickedState);
});

test('one file telling a viewing and that the show is dropped keeps the show dropped', function () {
    $show = makeShowTree([1 => ['2020-01-01']]);
    $user = User::factory()->create();

    runFakeImport($user, [
        new MediaRow(subjectOf($show), drop: new ImportDrop(CarbonImmutable::parse('2026-02-01 20:00'))),
        new MediaRow(subjectOf(episodeOf($show, 1, 1)), plays: [new ImportPlay(CarbonImmutable::parse('2026-03-01 20:00'))]),
    ]);

    // The status is what the source says the title is in now; the drop's
    // day it gave is only the best it knew.
    expect(librarySnapshot($user, [$show])['states']["show:{$show->provider}:{$show->provider_ref}"]['status'])->toBe('dropped');
});

test('a game put on hold, then finished: the later viewing closes the hold either way', function () {
    $game = MediaItem::factory()->game()->create();
    $paused = CarbonImmutable::parse('2026-02-01 20:00');
    $finished = CarbonImmutable::parse('2026-03-01 20:00');

    [$clicked, $imported] = bothWays(
        [$game],
        function (User $user) use ($game, $finished) {
            app(SetMediaStatus::class)->execute($user, $game, MediaUserStatus::OnHold);
            app(MarkWatched::class)->execute($user, $game, WatchedAt::at($finished));
        },
        [new MediaRow(subjectOf($game), plays: [new ImportPlay($finished)], status: new ImportStatus(MediaUserStatus::OnHold, $paused))],
    );

    expect($clicked['openAttempts'])->toBe([])
        ->and($imported)->toBe($clicked);
});

test('a drop of a show the user is watching again lands in the run under way on its date, clicked or imported', function () {
    $show = makeShowTree([1 => ['2020-01-01']]);
    $episode = episodeOf($show, 1, 1);
    $dropped = CarbonImmutable::parse('2026-07-01 20:00');

    [$clicked, $imported] = bothWays(
        [$show],
        function (User $user) use ($show, $dropped) {
            app(DropAttempt::class)->execute($user, $show, WatchedAt::at($dropped));
        },
        [new MediaRow(subjectOf($show), drop: new ImportDrop($dropped))],
    );

    expect($clicked['plays'])->toBe($imported['plays']);

    $rewatching = User::factory()->create();
    app(MarkWatched::class)->execute($rewatching, $episode, WatchedAt::at(CarbonImmutable::parse('2026-05-01 20:00')));
    test()->travelTo(CarbonImmutable::parse('2026-06-01 12:00'));
    app(StartRewatch::class)->execute($rewatching, $show);
    test()->travelTo(CarbonImmutable::parse('2026-10-10 12:00'));
    $importing = User::factory()->create();
    app(MarkWatched::class)->execute($importing, $episode, WatchedAt::at(CarbonImmutable::parse('2026-05-01 20:00')));
    test()->travelTo(CarbonImmutable::parse('2026-06-01 12:00'));
    app(StartRewatch::class)->execute($importing, $show);
    test()->travelTo(CarbonImmutable::parse('2026-10-10 12:00'));

    app(DropAttempt::class)->execute($rewatching, $show, WatchedAt::at($dropped));
    runFakeImport($importing, [new MediaRow(subjectOf($show), drop: new ImportDrop($dropped))]);

    $cycleOfDrop = fn (User $user) => collect(librarySnapshot($user, [$show])['plays'])->firstWhere(2, 'dropped')[4];

    expect($cycleOfDrop($rewatching))->toBe(2)
        ->and($cycleOfDrop($importing))->toBe(2);
});

test('one file telling a show dropped keeps it dropped whichever row comes first, dated or not', function (bool $dropFirst, ?string $droppedOn) {
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $user = User::factory()->create();
    $drop = new MediaRow(subjectOf($show), drop: new ImportDrop($droppedOn === null ? null : CarbonImmutable::parse($droppedOn)));
    $viewings = new MediaRow(subjectOf(episodeOf($show, 1, 1)), plays: [new ImportPlay(CarbonImmutable::parse('2021-03-01 20:00'))]);

    $run = runFakeImport($user, $dropFirst ? [$drop, $viewings] : [$viewings, $drop]);

    expect(librarySnapshot($user, [$show])['states']["show:{$show->provider}:{$show->provider_ref}"]['status'])->toBe('dropped')
        ->and($run->summary)->toContain('plays: 1', 'drops: 1');
})->with([
    'drop first, dated before the viewing' => [true, '2021-02-01 20:00'],
    'drop last, dated before the viewing' => [false, '2021-02-01 20:00'],
    'drop last, without a date' => [false, null],
]);

test('a show the user watched on here still refuses an older drop from a later import', function () {
    $show = makeShowTree([1 => ['2020-01-01']]);
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2021-03-01 20:00')));

    $run = runFakeImport($user, [new MediaRow(subjectOf($show), drop: new ImportDrop(CarbonImmutable::parse('2021-02-01 20:00')))]);

    expect($run->summary)->toContain('drops: 0')
        ->and(librarySnapshot($user, [$show])['states']["show:{$show->provider}:{$show->provider_ref}"]['status'])->not->toBe('dropped');
});

test('a game on hold and finished later in one file is finished whichever row comes first', function () {
    $game = MediaItem::factory()->game()->create();
    $user = User::factory()->create();

    runFakeImport($user, [
        new MediaRow(subjectOf($game), status: new ImportStatus(MediaUserStatus::OnHold, CarbonImmutable::parse('2026-02-01 20:00'))),
        new MediaRow(subjectOf($game), plays: [new ImportPlay(CarbonImmutable::parse('2026-03-01 20:00'))]),
    ]);

    expect(librarySnapshot($user, [$game])['openAttempts'])->toBe([]);
});

test('a viewing without a date of a title never released imports once, however often the file does', function () {
    $movie = MediaItem::factory()->movie()->create(['release_date' => null]);
    $user = User::factory()->create();
    $rows = [new MediaRow(subjectOf($movie), plays: [new ImportPlay])];

    runFakeImport($user, $rows);
    test()->travelTo(CarbonImmutable::parse('2026-10-11 12:00'));
    $second = runFakeImport($user, $rows);

    expect($user->plays()->count())->toBe(1)
        ->and($second->summary)->toContain('plays: 0');
});

test('a later run a viewing brings without the run itself is announced like one the file brings', function () {
    $show = makeShowTree([1 => ['2020-01-01']]);
    $user = User::factory()->create();
    Event::fake([RewatchStarted::class]);

    app(BackfillPlay::class)->execute($user, episodeOf($show, 1, 1), CarbonImmutable::parse('2026-03-01 20:00'), cycle: 2);

    expect(WatchCycle::query()->where('user_id', $user->id)->value('number'))->toBe(2);
    Event::assertDispatchedTimes(RewatchStarted::class, 1);
});

test('a counter a first import lacked fills in, and the filling is announced', function () {
    enableGamesModule();
    $game = MediaItem::factory()->game()->create();
    $book = MediaItem::factory()->book()->create();
    $user = User::factory()->create();
    $finished = CarbonImmutable::parse('2026-03-01 20:00');
    runFakeImport($user, [
        new MediaRow(subjectOf($game), plays: [new ImportPlay($finished)]),
        new MediaRow(subjectOf($book), status: new ImportStatus(MediaUserStatus::InProgress, $finished)),
    ]);

    Event::fake([PlayAmountChanged::class, ProgressLogged::class]);
    runFakeImport($user, [
        new MediaRow(subjectOf($game), plays: [new ImportPlay($finished, amount: 1200)]),
        new MediaRow(subjectOf($book), status: new ImportStatus(MediaUserStatus::InProgress, $finished, 120)),
    ]);

    expect($user->plays()->value('amount'))->toBe(1200)
        ->and($user->mediaStates()->value('progress'))->toBe(120);
    Event::assertDispatched(PlayAmountChanged::class, fn (PlayAmountChanged $event): bool => $event->amount === 1200);
    Event::assertDispatched(ProgressLogged::class, fn (ProgressLogged $event): bool => $event->progress === 120);
});

test('a game played here and finished by an import keeps the hours logged here, as a click does', function () {
    enableGamesModule();
    $game = MediaItem::factory()->game()->create();
    $clicked = User::factory()->create();
    $imported = User::factory()->create();

    foreach ([$clicked, $imported] as $user) {
        test()->travelTo(CarbonImmutable::parse('2026-02-01 20:00'));
        app(LogProgress::class)->execute($user, $game, 300, null);
    }

    test()->travelTo(CarbonImmutable::parse('2026-10-10 12:00'));
    app(MarkWatched::class)->execute($clicked, $game, WatchedAt::at(CarbonImmutable::parse('2026-03-01 20:00')));
    runFakeImport($imported, [new MediaRow(subjectOf($game), plays: [new ImportPlay(CarbonImmutable::parse('2026-03-01 20:00'))])]);

    expect($imported->plays()->value('amount'))->toBe(300)
        ->and(librarySnapshot($imported, [$game]))->toBe(librarySnapshot($clicked, [$game]));
});

test('an imported viewing closes a pause changed before it and leaves one changed at or after it', function (string $pausedAt, bool $closed) {
    $game = MediaItem::factory()->game()->create();
    $user = User::factory()->create();
    test()->travelTo(CarbonImmutable::parse($pausedAt));
    app(SetMediaStatus::class)->execute($user, $game, MediaUserStatus::OnHold);
    test()->travelTo(CarbonImmutable::parse('2026-10-10 12:00'));

    runFakeImport($user, [new MediaRow(subjectOf($game), plays: [new ImportPlay(CarbonImmutable::parse('2026-03-01 20:00'))])]);

    expect($user->mediaStates()->count())->toBe($closed ? 0 : 1);
})->with([
    'paused before' => ['2026-02-01 20:00', true],
    'paused at the same moment' => ['2026-03-01 20:00', false],
    'paused after' => ['2026-04-01 20:00', false],
]);

test('an imported viewing lifts a season drop recorded before it only once the season is fully watched', function () {
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);
    $season = seasonOf($show, 1);
    $user = User::factory()->create();
    test()->travelTo(CarbonImmutable::parse('2026-02-01 20:00'));
    app(DropAttempt::class)->execute($user, $season, WatchedAt::now());
    test()->travelTo(CarbonImmutable::parse('2026-10-10 12:00'));
    $seasonDrops = fn (): int => $user->plays()->where('media_item_id', $season->id)->where('outcome', 'dropped')->count();

    runFakeImport($user, [new MediaRow(subjectOf(episodeOf($show, 1, 1)), plays: [new ImportPlay(CarbonImmutable::parse('2026-03-01 20:00'))])]);
    expect($seasonDrops())->toBe(1);

    runFakeImport($user, [new MediaRow(subjectOf(episodeOf($show, 1, 2)), plays: [new ImportPlay(CarbonImmutable::parse('2026-03-02 20:00'))])]);
    expect($seasonDrops())->toBe(0);
});

test('an entry dated by the source\'s last activity is one entry however that date moves', function () {
    enableGamesModule();
    $game = MediaItem::factory()->game()->create();
    $user = User::factory()->create();
    $backfill = app(BackfillPlay::class);

    // A launcher's "last played": the second export of the same history
    // carries a later date and more minutes, not a second play.
    expect($backfill->execute($user, $game, CarbonImmutable::parse('2026-03-01 20:00'), amount: 120, dating: PlayDating::Activity))->toBeTrue()
        ->and($backfill->execute($user, $game, CarbonImmutable::parse('2026-05-09 21:30'), amount: 300, dating: PlayDating::Activity))->toBeFalse()
        ->and(Play::query()->where('user_id', $user->id)->count())->toBe(1);

    // Two viewings dated by when they happened stay two.
    $movie = MediaItem::factory()->create();
    $backfill->execute($user, $movie, CarbonImmutable::parse('2026-03-01 20:00'));
    $backfill->execute($user, $movie, CarbonImmutable::parse('2026-05-09 21:30'));

    expect(Play::query()->where('user_id', $user->id)->where('media_item_id', $movie->id)->count())->toBe(2);
});
