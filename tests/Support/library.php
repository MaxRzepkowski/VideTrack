<?php

use App\Actions\Library\SetCreatorFollow;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Enums\FollowMode;
use App\Events\Media\TitleRefreshed;
use App\Library\LibraryProjector;
use App\Listeners\ProjectLibraryWrites;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;

// Library rows built without HTTP.

/**
 * Factory-built show hierarchy for cascade/resolver tests (no HTTP).
 *
 * @param  array<int, list<string|null>>  $seasons  season number => episode air dates
 */
function makeShowTree(array $seasons): MediaItem
{
    $show = MediaItem::factory()->show()->create();

    foreach ($seasons as $number => $airDates) {
        $season = MediaItem::factory()->season($show, $number)->create();

        foreach (array_values($airDates) as $index => $airDate) {
            MediaItem::factory()->episode($season, $index + 1, $airDate)->create();
        }
    }

    return $show;
}

function seasonOf(MediaItem $show, int $number): MediaItem
{
    return $show->children()->where('number', $number)->firstOrFail();
}

function episodeOf(MediaItem $show, int $season, int $episode): MediaItem
{
    return seasonOf($show, $season)->children()->where('number', $episode)->firstOrFail();
}

/** The bell on a materialized collection, rung the way the collection page rings it. */
function followCollection(User $user, MediaList $collection, ?FollowMode $mode): void
{
    $source = $collection->collectionSource() ?? throw new LogicException('Not a collection.');

    app(SetCreatorFollow::class)->execute($user, new CreatorRef(CreatorKind::Collection, $source['provider'], $source['ref']), $mode, 'en');
}

/** The provider was asked about the title again (the metadata refresh): new episodes, dates and lengths count from here. */
function refreshTitle(MediaItem $root): void
{
    Event::dispatch(new TitleRefreshed($root->id));
}

/**
 * History the test wrote past the actions (rows made directly) is worked out
 * the way an import's is: once, when it is all in place.
 */
function projectLibrary(User $user): void
{
    app(LibraryProjector::class)->rebuild($user);
}

/** {@see projectLibrary()} for every account the test made. */
function projectEveryLibrary(): void
{
    User::query()->get()->each(fn (User $user) => projectLibrary($user));
}

/**
 * After every feature test whose events ran for real: the projection the
 * writes kept is the one the history makes from scratch. A difference is a
 * write the projection did not hear about.
 */
function assertProjectionMatchesHistory(): void
{
    if (projectionMissedEvents()) {
        return;
    }

    $rows = fn (): array => LibraryEntry::query()
        ->orderBy('user_id')->orderBy('media_item_id')
        ->get()
        ->map(fn (LibraryEntry $entry): array => Arr::except($entry->getAttributes(), 'id'))
        ->all();

    $kept = $rows();

    DB::beginTransaction();

    try {
        projectEveryLibrary();
        $rebuilt = $rows();
    } finally {
        DB::rollBack();
    }

    expect($kept)->toBe($rebuilt, 'The projection drifted from the history.');
}

/**
 * Whether the test faked an event the projection listens to: its writes
 * never reached the projection, so there is nothing to hold it to. Faking
 * other events leaves the guard on.
 */
function projectionMissedEvents(): bool
{
    $events = Event::getFacadeRoot();

    if (! $events instanceof EventFake) {
        return false;
    }

    $heard = (new ReflectionMethod(ProjectLibraryWrites::class, 'handle'))->getParameters()[0]->getType();
    $fakes = new ReflectionMethod($events, 'shouldFakeEvent');

    foreach ($heard instanceof ReflectionUnionType ? $heard->getTypes() : [$heard] as $type) {
        if ($type instanceof ReflectionNamedType && $fakes->invoke($events, $type->getName(), [])) {
            return true;
        }
    }

    return false;
}

/**
 * The page of a list where it lives: a user's under its owner, a collection
 * at its provider address.
 */
function listPage(MediaList $list): string
{
    $source = $list->collectionSource();

    if ($source !== null) {
        return route('collections.show', $source);
    }

    $list->loadMissing('user');

    return route('users.lists.show', ['user' => $list->user?->username, 'list' => $list->id]);
}
