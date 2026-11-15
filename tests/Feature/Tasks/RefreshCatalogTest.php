<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Media\PlanCatalogRefresh;
use App\Actions\Media\RefreshDueTitles;
use App\Catalog\TitleStore;
use App\Enums\FollowMode;
use App\Enums\TaskRunStatus;
use App\Jobs\RefreshCatalog;
use App\Jobs\RefreshCatalogBatch;
use App\Library\WatchedAt;
use App\Models\ChangeCursor;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\TaskRun;
use App\Models\TitleRefresh;
use App\Models\User;
use App\Tasks\TaskName;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/** @param  array<string, mixed>|null  $show  TMDB's answer for the show, the fixture by default */
function fakePersistedShow(?array $show = null): MediaItem
{
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer($show),
    ]);

    return app(TitleStore::class)->persist('show', '1399', 'en');
}

/**
 * Films on one user's watchlist, so the daily set holds them.
 *
 * @return list<MediaItem>
 */
function watchlistedFilms(int $count): array
{
    $user = User::factory()->create();
    $films = [];

    foreach (range(1, $count) as $position) {
        $films[] = $film = MediaItem::factory()->movie()->create();
        $user->watchlist()->items()->create(['media_item_id' => $film->id, 'position' => $position]);
    }

    return $films;
}

/** TMDB answers every film by the ref asked for, and lists no changes; $before runs first and may answer instead. */
function fakeFilmDetails(?Closure $before = null): void
{
    Http::fake([
        ...providerDictionaryFakes(),
        // A run after the first reads the lists of changes: nothing new.
        'api.themoviedb.org/3/movie/changes*' => Http::response(['results' => [], 'page' => 1, 'total_pages' => 1]),
        'api.themoviedb.org/3/tv/changes*' => Http::response(['results' => [], 'page' => 1, 'total_pages' => 1]),
        'api.themoviedb.org/3/movie/*' => function (Request $request) use ($before) {
            // Every matching stub is called, the lists of changes included.
            if (preg_match('#/movie/(\d+)#', $request->url(), $match) !== 1) {
                return null;
            }

            $answer = $before === null ? null : $before($match[1]);

            return $answer ?? Http::response([...jsonFixture('tmdb/movie'), 'id' => (int) $match[1]]);
        },
    ]);
}

function waitsNow(MediaItem $root): bool
{
    $row = TitleRefresh::query()->find($root->id);

    return $row?->due_at !== null && $row->due_at->lte(now());
}

test('the daily refresh asks about played shows under one task run', function () {
    Http::fake(providerDictionaryFakes());

    $show = fakePersistedShow();

    $user = User::factory()->create();
    $episode = MediaItem::query()->where('type', 'episode')->firstOrFail();
    app(MarkWatched::class)->execute($user, $episode, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    MediaItem::query()->update(['refreshed_at' => now()->subWeek()]);
    // refreshed_at moves only on a real change, so the stored show is made
    // stale here; the refresh brings the provider's title back.
    $show->update(['title' => 'An outdated title']);

    RefreshCatalog::queueTask();

    $run = TaskRun::query()->sole();

    expect($run->name)->toBe(TaskName::RefreshCatalog->value)
        ->and($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->user_id)->toBeNull()
        ->and($run->summary)->toContain('Refreshed 1 of 1 titles (1 of 1 active)')
        ->and($run->errors)->toBeNull()
        ->and($show->fresh()->title)->toBe('Game of Thrones')
        ->and($show->fresh()->refreshed_at->isAfter(now()->subMinute()))->toBeTrue()
        ->and(waitsNow($show))->toBeFalse();
});

test('the cycle asks about a root nobody tracks', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');
    // Make the stored copy stale so the refresh has a real change to write back.
    $movie->forceFill(['title' => 'Stale Title', 'refreshed_at' => now()->subWeek()])->save();

    RefreshCatalog::queueTask();

    expect(TaskRun::query()->sole()->summary)->toContain('1 of 1 cycle')
        ->and($movie->fresh()->title)->not->toBe('Stale Title')
        ->and($movie->fresh()->refreshed_at->isAfter(now()->subMinute()))->toBeTrue();
});

test('an unchanged item is not rewritten on refresh', function () {
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie')), ...providerDictionaryFakes()]);

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');

    // Backdate the row; an unchanged refresh must issue no write, so updated_at
    // (and refreshed_at) stay put — the churn optimization.
    DB::table('media_items')->where('id', $movie->id)->update(['updated_at' => '2020-01-01 00:00:00']);

    app(TitleStore::class)->refresh($movie->fresh(), 'en');

    expect($movie->fresh()->updated_at->toDateString())->toBe('2020-01-01');
});

test('a provider failure is collected without failing the run, and the root waits until tomorrow', function () {
    // Factory-built tree: no earlier Http stubs, the 500 catch-all wins.
    $show = makeShowTree([1 => ['2020-01-01']]);

    $user = User::factory()->create();
    app(MarkWatched::class)->execute(
        $user,
        $show->children()->firstOrFail()->children()->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-01-01')),
    );

    Http::fake(['*' => Http::response([], 500)]);

    RefreshCatalog::queueTask();

    $run = TaskRun::query()->sole();

    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('Refreshed 0 of 1')
        ->and($run->errors)->toHaveCount(1)
        ->and($run->errors[0])->toContain("show:{$show->provider_ref}")
        ->and(TitleRefresh::query()->findOrFail($show->id)->due_at?->equalTo(now()->addHours(RefreshDueTitles::HOURS_AFTER_FAILURE)))->toBeTrue();
});

test('an item gone from the provider is kept, not errored', function () {
    $show = makeShowTree([1 => ['2020-01-01']]);

    $user = User::factory()->create();
    app(MarkWatched::class)->execute(
        $user,
        $show->children()->firstOrFail()->children()->firstOrFail(),
        WatchedAt::at(CarbonImmutable::parse('2026-01-01')),
    );

    // The whole tree now 404s at TMDB (removed or merged upstream).
    Http::fake(['*' => Http::response([], 404)]);

    RefreshCatalog::queueTask();

    $run = TaskRun::query()->sole();

    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('Refreshed 0 of 1', 'kept (still referenced)')
        // The kept item is listed (not just counted) so an operator sees which.
        // Noted, not an error: nothing went wrong.
        ->and($run->errors)->toBeNull()
        ->and($run->details)->toHaveCount(1)
        ->and($run->details[0])->toContain("show:{$show->provider_ref}", 'kept as an archive entry because it has user data')
        // The record and the user's history on it survive the 404.
        ->and(MediaItem::query()->whereKey($show->id)->exists())->toBeTrue()
        // The provider answered, if only to say it is gone.
        ->and(waitsNow($show))->toBeFalse();
});

test('an orphan gone from the provider is pruned', function () {
    // A root nobody attached anything to: the cycle reaches it.
    $movie = MediaItem::factory()->movie()->create();

    Http::fake(['*' => Http::response([], 404)]);

    RefreshCatalog::queueTask();

    $run = TaskRun::query()->sole();

    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('Removed 1 gone from the provider')
        // No user data referenced it, so the 404 prunes it.
        ->and(MediaItem::query()->whereKey($movie->id)->exists())->toBeFalse();
});

test('a title gone from the provider is kept while someone follows it', function () {
    // Only a bell: no play, rating or list. Pruning would delete the follow.
    $movie = MediaItem::factory()->movie()->create();
    $follow = Follow::factory()->create([
        'user_id' => User::factory()->create()->id,
        'media_item_id' => $movie->id,
        'mode' => FollowMode::Follow,
    ]);

    Http::fake(['*' => Http::response([], 404)]);

    RefreshCatalog::queueTask();

    expect(MediaItem::query()->whereKey($movie->id)->exists())->toBeTrue()
        ->and(Follow::query()->whereKey($follow->id)->exists())->toBeTrue();
});

test('a title gone from the provider that only a followed creator rang a bell on is removed, without telling anyone', function () {
    // The user follows an actor; the bell on the actor's new show was the creator's, not theirs.
    $show = makeShowTree([1 => ['2026-12-01']]);
    $follow = Follow::factory()->create(['media_item_id' => $show->id, 'mode' => FollowMode::Follow]);
    $follow->viaPeople()->attach(Person::factory()->create());

    Http::fake(['*' => Http::response([], 404)]);

    RefreshCatalog::queueTask();

    $run = TaskRun::query()->sole();

    expect($run->summary)->toContain('Removed 1 gone from the provider')
        ->and($run->summary)->not->toContain('notifications')
        ->and(MediaItem::query()->whereKey($show->id)->exists())->toBeFalse()
        ->and(Follow::query()->whereKey($follow->id)->exists())->toBeFalse();
});

test('a mute stays the user\'s even when a followed creator backs it', function () {
    $movie = MediaItem::factory()->movie()->create();
    $follow = Follow::factory()->create(['media_item_id' => $movie->id, 'mode' => FollowMode::Mute]);
    $follow->viaPeople()->attach(Person::factory()->create());

    Http::fake(['*' => Http::response([], 404)]);

    RefreshCatalog::queueTask();

    expect(MediaItem::query()->whereKey($movie->id)->exists())->toBeTrue()
        ->and(Follow::query()->whereKey($follow->id)->exists())->toBeTrue();
});

test('a provider failing several roots in a row is not asked again this run, while another carries on', function () {
    enableComicsModule();
    $films = watchlistedFilms(RefreshDueTitles::FAILURES_BEFORE_STOP + 1);
    $comic = MediaItem::factory()->comic()->create(['provider_ref' => '4207']);
    User::query()->firstOrFail()->watchlist()->items()->create(['media_item_id' => $comic->id, 'position' => 99]);

    Http::fake([
        ...providerDictionaryFakes(),
        'api.themoviedb.org/*' => Http::response([], 500),
        'comicvine.gamespot.com/api/volume/4050-4207/*' => Http::response(jsonFixture('comicvine/volume')),
    ]);

    RefreshCatalog::queueTask();

    $run = TaskRun::query()->sole();
    $last = $films[RefreshDueTitles::FAILURES_BEFORE_STOP];

    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->errors)->toHaveCount(RefreshDueTitles::FAILURES_BEFORE_STOP)
        ->and($run->summary)->toContain('Stopped asking tmdb')
        // The film after the stop was never asked: it still waits, now.
        ->and(waitsNow($last))->toBeTrue()
        // The other provider's root went ahead.
        ->and(waitsNow($comic))->toBeFalse()
        ->and(TitleRefresh::query()->findOrFail($comic->id)->asked_at)->not->toBeNull();
});

test('a provider asking to be waited out is left alone until then, and the next batch waits for it', function () {
    enableComicsModule();
    [$film] = watchlistedFilms(1);
    $comic = MediaItem::factory()->comic()->create(['provider_ref' => '4207']);
    User::query()->firstOrFail()->watchlist()->items()->create(['media_item_id' => $comic->id, 'position' => 2]);

    $busy = true;
    fakeFilmDetails(function () use (&$busy) {
        return $busy ? Http::response(['status_code' => 25], 429, ['Retry-After' => '120']) : null;
    });
    Http::fake(['comicvine.gamespot.com/api/volume/4050-4207/*' => Http::response(jsonFixture('comicvine/volume'))]);

    $run = TaskRun::queueTask(TaskName::RefreshCatalog->value);
    $run->markStarted();
    $batch = new RefreshCatalogBatch($run, app(PlanCatalogRefresh::class)->execute());
    app()->call([$batch, 'handle']);

    $next = $batch->handOver();

    expect(waitsNow($comic))->toBeFalse()
        ->and(waitsNow($film))->toBeTrue()
        ->and($next)->toBeInstanceOf(RefreshCatalogBatch::class)
        ->and($next?->delay)->toBe(120)
        ->and($run->fresh()?->status)->toBe(TaskRunStatus::Started)
        ->and($run->fresh()?->summary)->toContain('Waiting about 2 min until tmdb may be asked again');

    $busy = false;
    $this->travel(121)->seconds();
    app()->call([$next, 'handle']);

    expect(waitsNow($film))->toBeFalse()
        ->and($next?->handOver())->toBeNull()
        ->and($run->fresh()?->status)->toBe(TaskRunStatus::Success)
        ->and($run->fresh()?->summary)->toContain('Refreshed 2 of 2');
});

test('a run broken midway loses nothing: the next run asks about what is left', function () {
    [$first, $second, $third] = watchlistedFilms(3);

    $broken = true;
    fakeFilmDetails(function (string $ref) use (&$broken, $second) {
        if ($broken && $ref === $second->provider_ref) {
            throw new RuntimeException('The worker went down.');
        }

        return null;
    });

    expect(fn () => RefreshCatalog::queueTask())->toThrow(RuntimeException::class);

    expect(waitsNow($first))->toBeFalse()
        ->and(waitsNow($second))->toBeTrue()
        ->and(waitsNow($third))->toBeTrue()
        ->and(TaskRun::query()->sole()->status)->toBe(TaskRunStatus::Failure);

    $broken = false;
    RefreshCatalog::queueTask();

    expect(waitsNow($second))->toBeFalse()
        ->and(waitsNow($third))->toBeFalse()
        ->and(TaskRun::query()->latest('id')->firstOrFail()->status)->toBe(TaskRunStatus::Success);
});

test('a run goes on over as many batches as it takes, under one task run', function () {
    watchlistedFilms(4);

    // Each film takes half a batch's working time.
    fakeFilmDetails(function () {
        Carbon::setTestNow(now()->addSeconds(RefreshCatalogBatch::WORK_SECONDS / 2));

        return null;
    });

    $batches = 0;
    Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$batches): void {
        if ($event->job->resolveName() === RefreshCatalogBatch::class) {
            $batches++;
        }
    });

    RefreshCatalog::queueTask();

    expect($batches)->toBe(2)
        ->and(TaskRun::query()->sole()->status)->toBe(TaskRunStatus::Success)
        ->and(TaskRun::query()->sole()->summary)->toContain('Refreshed 4 of 4');
});

test('the run names the title it is asking about, and says it waits in the queue between batches', function () {
    [$first, $second] = watchlistedFilms(2);
    $first->update(['title' => 'The Matrix']);
    $seen = [];
    fakeFilmDetails(function () use (&$seen) {
        $seen[] = TaskRun::query()->sole()->summary;
        // The first title outlasts the batch's time, so the second is the next batch's.
        $this->travel(RefreshCatalogBatch::WORK_SECONDS + 1)->seconds();

        return null;
    });

    $run = TaskRun::queueTask(TaskName::RefreshCatalog->value);
    $run->markStarted();
    $batch = new RefreshCatalogBatch($run, app(PlanCatalogRefresh::class)->execute());
    app()->call([$batch, 'handle']);

    expect($seen[0])->toBe('Refreshing title 1 of 2: The Matrix…')
        ->and($batch->handOver())->toBeInstanceOf(RefreshCatalogBatch::class)
        ->and($run->fresh()?->summary)->toBe('1 of 2 done. Waiting in the queue while the worker finishes other jobs…')
        ->and(waitsNow($second))->toBeTrue();
});

test('a title whose refresh takes the worker past the memory threshold is named in the run', function () {
    config(['tracker.heavy_title_megabytes' => 0]);
    [$film] = watchlistedFilms(1);
    fakeFilmDetails();

    RefreshCatalog::queueTask();

    expect(TaskRun::query()->sole()->details)->toHaveCount(1)
        ->and(TaskRun::query()->sole()->details[0])->toContain("movie:{$film->provider_ref}", "the worker's memory peaked at");
});

/**
 * The stored show, watched by somebody, with the list of changed shows read yesterday.
 *
 * @param  array<string, mixed>|null  $answer  TMDB's answer for the show
 */
function watchedShowWithListReadYesterday(?array $answer = null): MediaItem
{
    Http::fake(providerDictionaryFakes());
    $show = fakePersistedShow($answer);
    app(MarkWatched::class)->execute(User::factory()->create(), episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    ChangeCursor::query()->create(['provider' => 'tmdb', 'type' => 'show', 'checked_until' => now()->subDay()]);
    ChangeCursor::query()->create(['provider' => 'tmdb', 'type' => 'movie', 'checked_until' => now()->subDay()]);

    return $show;
}

/** @param  list<int>  $shows */
function fakeShowChanges(array $shows): void
{
    Http::fake([
        'api.themoviedb.org/3/tv/changes*' => Http::response(['results' => array_map(fn (int $id): array => ['id' => $id], $shows), 'page' => 1, 'total_pages' => 1]),
        'api.themoviedb.org/3/movie/changes*' => Http::response(['results' => [], 'page' => 1, 'total_pages' => 1]),
    ]);
}

test('a show whose schedule changed without the list of changes naming it is noted and counted', function () {
    $show = watchedShowWithListReadYesterday();
    fakeShowChanges([]);
    // The stored episode carries a date the provider has since moved.
    episodeOf($show, 1, 2)->update(['release_date' => '2011-05-01']);

    RefreshCatalog::queueTask();

    $run = TaskRun::query()->sole();

    expect($run->summary)->toContain('The list of changes named 0 of 1 show titles that changed.')
        ->and($run->details)->toContain('show:1399 (Game of Thrones): changed, and the list of changes did not name it.');
});

test('a show the list named counts as caught, and a show that did not change counts for nothing', function () {
    $show = watchedShowWithListReadYesterday();
    fakeShowChanges([1399]);
    episodeOf($show, 1, 2)->update(['release_date' => '2011-05-01']);

    RefreshCatalog::queueTask();

    expect(TaskRun::query()->sole()->summary)->toContain('The list of changes named 1 of 1 show titles that changed.')
        ->and(collect(TaskRun::query()->sole()->details)->filter(fn (string $line): bool => str_contains($line, 'did not name it')))->toBeEmpty();

    // The next morning nothing moved: nothing to count.
    $this->travel(1)->day();
    RefreshCatalog::queueTask();

    expect(TaskRun::query()->latest('id')->firstOrFail()->summary)->toContain('named 0 of 0 show titles');
});

test('a new issue counts as a change, though a comic has no dates to move', function () {
    // The list and the volume are two requests at ComicVine's pace of one a second.
    $this->travelBack();
    enableComicsModule();
    $comic = MediaItem::factory()->comic()->create(['provider_ref' => '4207', 'title' => 'The Sandman', 'metadata' => ['issues' => 74]]);
    User::factory()->create()->watchlist()->items()->create(['media_item_id' => $comic->id, 'position' => 1]);
    // A second volume buys the second page: ComicVine reads two lists.
    MediaItem::factory()->comic()->create(['provider_ref' => '796', 'title' => 'The Sandman']);
    ChangeCursor::query()->create(['provider' => 'comicvine', 'type' => 'comic', 'checked_until' => now()->subDay()]);
    Http::fake([
        ...providerDictionaryFakes(),
        'comicvine.gamespot.com/api/volumes/*' => Http::response(['status_code' => 1, 'results' => []]),
        'comicvine.gamespot.com/api/issues/*' => Http::response(['status_code' => 1, 'results' => []]),
        'comicvine.gamespot.com/api/volume/*' => Http::response(jsonFixture('comicvine/volume')),
    ]);

    RefreshCatalog::queueTask();

    $run = TaskRun::query()->sole();

    expect($run->summary)->toContain('The list of changes named 0 of 1 comic titles that changed.')
        ->and($run->details)->toContain('comic:4207 (The Sandman): changed, and the list of changes did not name it.');
});

test('a tree that disagrees with its show on the next episode is noted', function () {
    // The show's details know an episode its batched seasons do not carry yet.
    watchedShowWithListReadYesterday([
        ...jsonFixture('tmdb/tv'),
        'next_episode_to_air' => ['season_number' => 2, 'episode_number' => 3, 'name' => 'What Is Dead May Never Die', 'air_date' => '2026-10-20'],
    ]);
    fakeShowChanges([]);

    RefreshCatalog::queueTask();

    expect(TaskRun::query()->sole()->details)->toContain('show:1399 (Game of Thrones): 1399:2:3 airs 2026-10-20 by the title, missing in the tree.');
});
