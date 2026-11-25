<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Media\BuildArchivedTitles;
use App\Catalog\TitleStore;
use App\Jobs\RefreshCatalog;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use App\Tracking\Surfaces\CalendarDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// A title its data source drops (TMDB merged "Cyberpunk: Edgerunners 2" into
// season 2 of the first show) stays in the libraries that keep it, as an
// archive entry: no future dates, one notification listing it, a page of its
// own, and gone once nobody keeps anything on it.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00'));
});

/** A show with one aired and one upcoming episode, on $user's watchlist. */
function keptShow(User $user): MediaItem
{
    $show = makeShowTree([1 => ['2026-10-01', '2026-10-20']]);
    $user->watchlist()->items()->create(['media_item_id' => $show->id, 'position' => 1]);

    return $show;
}

function sourceDropsEverything(): void
{
    Http::fake(['*' => Http::response(['status_message' => 'not found'], 404)]);
}

test('a title its source dropped becomes an archive entry and its holder hears once', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $show = keptShow($user);
    sourceDropsEverything();

    RefreshCatalog::queueTask();

    $episodes = MediaItem::query()->where('type', 'episode')->orderBy('number')->get();

    expect($show->fresh()->gone_at)->not->toBeNull()
        // The aired episode keeps its date; the one still ahead loses it.
        ->and($episodes[0]->release_date?->toDateString())->toBe('2026-10-01')
        ->and($episodes[1]->release_date)->toBeNull()
        ->and($user->notifications()->count())->toBe(1)
        ->and($other->notifications()->count())->toBe(0);

    /** @var DatabaseNotification $notification */
    $notification = $user->notifications()->firstOrFail();

    expect($notification->data['event'])->toBe('titles_removed')
        ->and(array_column($notification->data['items'], 'ref'))->toBe([$show->provider_ref]);

    // The next night it is still gone: no second notification.
    RefreshCatalog::queueTask();

    expect($user->notifications()->count())->toBe(1);
});

test('an episode out today stays aired when its title becomes an archive entry', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2026-10-01', '2026-10-10', '2026-10-20']]);
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-10-02')));
    sourceDropsEverything();

    RefreshCatalog::queueTask();

    // Out today is out: the source dropping the title takes away only what was still ahead.
    expect(episodeOf($show, 1, 2)->release_date?->toDateString())->toBe('2026-10-10')
        ->and(episodeOf($show, 1, 3)->release_date)->toBeNull();
});

test('an archive entry leaves the calendar', function () {
    $user = User::factory()->create();
    keptShow($user);

    expect(app(CalendarDays::class)->range($user, '2026-10-10', '2026-11-10'))->not->toBe([]);

    sourceDropsEverything();

    RefreshCatalog::queueTask();

    expect(app(CalendarDays::class)->range($user, '2026-10-10', '2026-11-10'))->toBe([]);
});

test('an archive entry nobody keeps any more is deleted on the next refresh', function () {
    $user = User::factory()->create();
    $show = keptShow($user);
    sourceDropsEverything();

    RefreshCatalog::queueTask();

    $user->watchlist()->items()->delete();

    RefreshCatalog::queueTask();

    expect(MediaItem::query()->whereKey($show->id)->exists())->toBeFalse();
});

test('a title its source has again is no archive entry any more', function () {
    Http::fake([
        'api.themoviedb.org/3/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        ...providerDictionaryFakes(),
    ]);

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');
    $movie->forceFill(['gone_at' => now()])->save();

    app(TitleStore::class)->refresh($movie, 'en');

    expect($movie->fresh()->gone_at)->toBeNull();
});

test('the season page of a removed show still links back to its show', function () {
    $user = User::factory()->create();
    $show = keptShow($user);
    sourceDropsEverything();

    $this->actingAs($user)
        ->get(route('season.details', ['ref' => $show->provider_ref, 'number' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('fromLibrary', true)
            ->where('removedAtSource', true)
            ->where('season.extra.showRef', $show->provider_ref)
            ->where('season.extra.showTitle', $show->title));
});

test('the removed-titles notification opens on a page listing every title', function () {
    $user = User::factory()->create();
    $show = keptShow($user);
    sourceDropsEverything();

    RefreshCatalog::queueTask();

    $notification = $user->notifications()->firstOrFail();

    $this->actingAs($user)
        ->get(route('notifications.show', ['notification' => $notification->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('NotificationDetails')
            ->where('items.0.media.ref', $show->provider_ref)
            ->where('items.0.available', true)
            ->where("userStates.show:{$show->provider_ref}.listIds", [$user->watchlist()->id]));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('the archive page shows the viewer\'s archive entries with their states, and only theirs', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $show = keptShow($user);
    $episode = MediaItem::query()->where('type', 'episode')->orderBy('number')->firstOrFail();
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-10-02')));
    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $show->id, 'rating' => 8]);
    sourceDropsEverything();

    RefreshCatalog::queueTask();

    $this->actingAs($user)
        ->get(route('library.archived'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media/ArchivedTitles')
            ->has('titles', 1)
            ->where('titles.0.media.ref', $show->provider_ref)
            // The cards draw what the viewer keeps from their states.
            ->where("userStates.show:{$show->provider_ref}.rating", 8)
            ->where("userStates.show:{$show->provider_ref}.listIds", [$user->watchlist()->id]));

    $this->actingAs($other)
        ->get(route('library.archived'))
        ->assertInertia(fn (Assert $page) => $page->has('titles', 0));
});

test('the archive asks the same few queries however many titles are archived', function () {
    $user = User::factory()->create();
    /** @return array{queries: list<QueryExecuted>, titles: int} */
    $archive = function () use ($user): array {
        $titles = [];
        $queries = queriesRunBy(function () use ($user, &$titles): void {
            $titles = app(BuildArchivedTitles::class)->execute($user);
        });

        return ['queries' => $queries, 'titles' => count($titles)];
    };

    // One show held through an episode play, one through the show itself.
    $first = makeShowTree([1 => ['2026-10-01']]);
    app(MarkWatched::class)->execute($user, MediaItem::query()->where('type', 'episode')->firstOrFail(), WatchedAt::at(CarbonImmutable::parse('2026-10-02')));
    $first->update(['gone_at' => now()]);
    $one = $archive();

    $second = makeShowTree([1 => ['2026-10-01', '2026-10-08']]);
    Rating::factory()->create(['user_id' => $user->id, 'media_item_id' => $second->id, 'rating' => 7]);
    $second->update(['gone_at' => now()]);
    // Nobody's: archived, but not this user's.
    makeShowTree([1 => ['2026-10-01']])->update(['gone_at' => now()]);

    $three = $archive();

    expect($one['titles'])->toBe(1)
        ->and($three['titles'])->toBe(2);
    expectSameQueries($one['queries'], $three['queries']);
});
