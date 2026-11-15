<?php

use App\Actions\Library\ClearUserData;
use App\Actions\Library\MarkWatched;
use App\Actions\Library\StartRewatch;
use App\Actions\Library\UnmarkWatched;
use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\MediaRow;
use App\Library\LibraryProjector;
use App\Library\WatchedAt;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use App\Tasks\TaskName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/** @return array<int, LibraryEntry> the user's entries by position id */
function entriesOf(User $user): array
{
    return LibraryEntry::query()->where('user_id', $user->id)->get()->keyBy('media_item_id')->all();
}

test('a play projects every position of its title, the untouched episodes too', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08'], 2 => ['2021-01-01']]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $entries = entriesOf($user);

    // Show, two seasons, three episodes.
    expect($entries)->toHaveCount(6)
        ->and(collect($entries)->pluck('root_id')->unique()->all())->toBe([$show->id])
        ->and($entries[$show->id]->watched_episodes)->toBe(1)
        ->and($entries[$show->id]->next_episode_id)->toBe(episodeOf($show, 1, 2)->id)
        ->and($entries[$show->id]->played)->toBeTrue()
        ->and($entries[$show->id]->last_played_at?->toDateString())->toBe('2026-01-01')
        ->and($entries[seasonOf($show, 2)->id]->played)->toBeFalse()
        ->and($entries[episodeOf($show, 2, 1)->id]->watched)->toBeFalse();
});

test('a title whose history is gone has no entries left', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    app(MarkWatched::class)->execute($user, $movie, WatchedAt::now());
    expect(entriesOf($user))->toHaveCount(1);

    app(UnmarkWatched::class)->execute($user, $movie);
    expect(entriesOf($user))->toBe([]);
});

test('a rewatch moves every position of the title into the new cycle', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(StartRewatch::class)->execute($user, $show);

    $entries = entriesOf($user);

    expect(collect($entries)->pluck('cycle')->unique()->all())->toBe([2])
        ->and($entries[$show->id]->watched)->toBeFalse()
        ->and($entries[$show->id]->completed_cycles)->toBe(1);
});

test('an episode the provider adds later counts once the title is read again, for everyone on it', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01']]);

    app(MarkWatched::class)->execute($user, $show, WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($other, $show, WatchedAt::at(CarbonImmutable::parse('2026-02-01')));
    MediaItem::factory()->episode(seasonOf($show, 1), 2, '2026-07-01')->create();

    expect(entriesOf($user)[$show->id]->watched)->toBeTrue();

    refreshTitle($show);

    expect(entriesOf($user)[$show->id])
        ->watched->toBeFalse()
        ->aired_episodes->toBe(2)
        ->and(entriesOf($other)[$show->id])
        ->watched->toBeFalse()
        ->aired_episodes->toBe(2);
});

test('the daily task counts an episode that aired since the last write', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2026-10-11']]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    expect(entriesOf($user)[$show->id])
        ->aired_episodes->toBe(1)
        ->next_episode_id->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-11 00:05:00'));
    $run = runScheduledTask(TaskName::ProjectAiredEpisodes->value);

    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toBe(__('tasks.summary_aired_episodes', ['titles' => 1, 'accounts' => 1]))
        ->and(entriesOf($user)[$show->id])
        ->aired_episodes->toBe(2)
        ->next_episode_id->toBe(episodeOf($show, 1, 2)->id);
});

test('the daily task catches up on the days it slept through, as far back as its window', function () {
    $user = User::factory()->create();
    $within = makeShowTree([1 => ['2020-01-01', '2026-10-13']]);
    $beyond = makeShowTree([1 => ['2020-01-01', '2026-10-11']]);

    app(MarkWatched::class)->execute($user, episodeOf($within, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));
    app(MarkWatched::class)->execute($user, episodeOf($beyond, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    // Asleep until eight days after the second show's episode.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 00:05:00'));
    $run = runScheduledTask(TaskName::ProjectAiredEpisodes->value);

    expect($run->summary)->toBe(__('tasks.summary_aired_episodes', ['titles' => 1, 'accounts' => 1]))
        ->and(entriesOf($user)[$within->id]->aired_episodes)->toBe(2)
        // Out of the window: it waits for the title's next write or refresh.
        ->and(entriesOf($user)[$beyond->id]->aired_episodes)->toBe(1);

    refreshTitle($beyond);

    expect(entriesOf($user)[$beyond->id]->aired_episodes)->toBe(2);
});

test('writes in a batch are projected once, when the batch ends', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    app(LibraryProjector::class)->deferring($user, function () use ($user, $show): void {
        app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::now());
        app(MarkWatched::class)->execute($user, episodeOf($show, 1, 2), WatchedAt::now());

        expect(entriesOf($user))->toBe([]);
    });

    expect(entriesOf($user)[$show->id]->watched)->toBeTrue();
});

test('what a batch wrote before it failed is projected all the same', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();

    expect(fn () => app(LibraryProjector::class)->deferring($user, function () use ($user, $movie): void {
        app(MarkWatched::class)->execute($user, $movie, WatchedAt::now());

        throw new RuntimeException('The source broke off.');
    }))->toThrow(RuntimeException::class);

    expect(entriesOf($user)[$movie->id]->watched)->toBeTrue();
});

test('an import projects its titles in one write, however many it brings', function () {
    $user = User::factory()->create();
    $first = MediaItem::factory()->movie()->create(['provider_ref' => '603']);
    $second = MediaItem::factory()->movie()->create(['provider_ref' => '604']);

    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        $writes += preg_match('/^(insert|update|delete)\b.*library_entries/i', $query->sql);
    });

    runFakeImport($user, [
        new MediaRow(MediaSubject::direct('movie', 'tmdb', '603', 'The Matrix'), plays: [new ImportPlay(new CarbonImmutable('2025-01-10 20:00:00'))]),
        new MediaRow(MediaSubject::direct('movie', 'tmdb', '604', 'The Matrix Reloaded'), plays: [new ImportPlay(new CarbonImmutable('2025-01-11 20:00:00'))]),
    ]);

    expect($writes)->toBe(1)
        ->and(entriesOf($user)[$first->id]->watched)->toBeTrue()
        ->and(entriesOf($user)[$second->id]->watched)->toBeTrue();
});

test('a retried import works out what the attempt before it wrote and never projected', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['provider_ref' => '603']);

    // The first attempt wrote the play and was killed before its batch ended.
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'watched_at' => '2025-01-10 20:00:00']);

    runFakeImport($user, [
        new MediaRow(MediaSubject::direct('movie', 'tmdb', '603', 'The Matrix'), plays: [new ImportPlay(new CarbonImmutable('2025-01-10 20:00:00'))]),
    ]);

    expect($user->plays()->count())->toBe(1)
        ->and(entriesOf($user)[$movie->id]->watched)->toBeTrue();
});

test('the rebuild command works history written past the actions out, and drops what no history holds', function () {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create();
    $forgotten = MediaItem::factory()->movie()->create();

    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id]);
    LibraryEntry::query()->create(['user_id' => $user->id, 'media_item_id' => $forgotten->id, 'root_id' => $forgotten->id, 'watched' => true]);

    $this->artisan('vide:library:rebuild', ['user' => $user->username])->assertSuccessful();

    expect(array_keys(entriesOf($user)))->toBe([$movie->id])
        ->and(entriesOf($user)[$movie->id]->watched)->toBeTrue();

    $this->artisan('vide:library:rebuild')->expectsOutput('Rebuilt 1 libraries.')->assertSuccessful();
});

test('the rebuild command goes on past an account whose history it cannot work out', function () {
    $broken = User::factory()->create();
    $user = User::factory()->create();
    $first = MediaItem::factory()->show()->create();
    $second = MediaItem::factory()->season($first, 1)->create();
    $first->forceFill(['parent_id' => $second->id])->save();
    $movie = MediaItem::factory()->movie()->create();

    $cyclic = Play::factory()->create(['user_id' => $broken->id, 'media_item_id' => $second->id]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id]);

    $this->artisan('vide:library:rebuild')
        ->expectsOutput("The library of {$broken->username} could not be rebuilt.")
        ->expectsOutput('Rebuilt 1 libraries.')
        ->assertFailed();

    expect(entriesOf($user)[$movie->id]->watched)->toBeTrue();

    $cyclic->delete();
});

test('a title of a type the instance switched off is left for when it returns', function () {
    $user = User::factory()->create();
    $game = MediaItem::factory()->game()->create();
    $movie = MediaItem::factory()->movie()->create();

    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $game->id]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id]);

    // phpunit.xml leaves IGDB without keys, so games are not served here.
    projectLibrary($user);

    expect(array_keys(entriesOf($user)))->toBe([$movie->id]);
});

test('clearing the library takes its projection with it', function () {
    $user = User::factory()->create();

    app(MarkWatched::class)->execute($user, MediaItem::factory()->movie()->create(), WatchedAt::now());
    app(ClearUserData::class)->execute($user);

    expect(entriesOf($user))->toBe([]);
});

test('projecting a title nothing changed on writes nothing', function () {
    $user = User::factory()->create();
    $show = makeShowTree([1 => ['2020-01-01', '2020-01-08']]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-01-01')));

    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        $writes += preg_match('/^(insert|update|delete)\b.*library_entries/i', $query->sql);
    });

    app(LibraryProjector::class)->projectRoots($user, [$show->id]);

    expect($writes)->toBe(0);
});
