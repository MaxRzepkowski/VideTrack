<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** TMDB's search knowing the fixture's films by title, each with its year; "Nowhere Film" is nobody's. */
function fakeLetterboxdSearch(): void
{
    $film = fn (int $id, string $title, string $released): array => ['id' => $id, 'media_type' => 'movie', 'title' => $title, 'release_date' => $released];

    Http::fake(['*/search/multi*' => tmdbSearchAnswer([
        'The Matrix' => $film(603, 'The Matrix', '1999-03-31'),
        'Past Lives' => $film(666277, 'Past Lives', '2023-06-02'),
        'Red, White & Royal Blue' => $film(1010581, 'Red, White & Royal Blue', '2023-08-11'),
        'Amélie' => $film(194, 'Amélie', '2001-04-25'),
        'Dune: Part Two' => $film(693134, 'Dune: Part Two', '2024-02-27'),
    ])]);
}

/** @return array<string, MediaItem> */
function letterboxdFilms(): array
{
    return [
        'matrix' => MediaItem::factory()->movie()->create(['provider_ref' => '603']),
        'pastLives' => MediaItem::factory()->movie()->create(['provider_ref' => '666277']),
        'royalBlue' => MediaItem::factory()->movie()->create(['provider_ref' => '1010581']),
        'amelie' => MediaItem::factory()->movie()->create(['provider_ref' => '194']),
        'dune' => MediaItem::factory()->movie()->create(['provider_ref' => '693134']),
    ];
}

test('a Letterboxd export offers its history, ratings, watchlist, likes and lists, leaving deleted entries out', function () {
    $path = putZipFixture('letterboxd/export');

    $manifest = app(ImportSourceRegistry::class)->get('letterboxd')->analyze(ImportInput::file(Storage::disk('local')->path($path)));

    expect($manifest->valid)->toBeTrue()
        ->and(array_map(fn ($category) => [$category->key, $category->count], $manifest->categories))->toBe([
            // Four diary entries, and two films only marked watched.
            ['history', 6],
            ['ratings', 3],
            ['watchlist', 1],
            ['favorites', 1],
        ])
        ->and(array_map(fn ($list) => [$list->id, $list->name, $list->itemCount], $manifest->lists))->toBe([
            ['lists/late-night-picks.csv', 'Late Night Picks', 2],
        ]);
});

test('a Letterboxd export brings over the diary, ratings, watchlist, likes and a chosen list by title and year', function () {
    fakeLetterboxdSearch();
    $user = User::factory()->create();
    $films = letterboxdFilms();

    $taskRun = runImportFile($user, 'letterboxd', putZipFixture('letterboxd/export'), new ImportSelection(
        ['history', 'ratings', 'watchlist', 'favorites'],
        ['lists/late-night-picks.csv'],
    ));

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->summary)->toContain('plays: 5', 'ratings: 3', 'favorites: 1', 'list items: 3', 'skipped entries: 1')
        ->and($taskRun->errors)->toBe(['Nowhere Film (2020): no match for an entry without ids, nor by name.']);

    // A rewatch is a second viewing; a diary entry without a watched date is dated when it was logged.
    expect($user->plays()->where('media_item_id', $films['matrix']->id)->orderBy('watched_at')->get()->map(fn ($play) => $play->watched_at->toDateString())->all())
        ->toBe(['2024-01-05', '2025-03-01'])
        ->and($user->plays()->where('media_item_id', $films['royalBlue']->id)->firstOrFail()->watched_at->toDateString())->toBe('2024-05-20')
        ->and($user->plays()->where('media_item_id', $films['amelie']->id)->count())->toBe(1)
        // Half a star is the bottom of the scale, not nothing.
        ->and($user->ratings()->where('media_item_id', $films['amelie']->id)->value('rating'))->toBe(1)
        ->and($user->ratings()->where('media_item_id', $films['matrix']->id)->value('rating'))->toBe(9)
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$films['dune']->id])
        ->and($user->favorites()->pluck('media_item_id')->all())->toBe([$films['pastLives']->id]);

    $list = $user->lists()->where('title', 'Late Night Picks')->firstOrFail();

    expect($list->description)->toBe('Films for after midnight, mostly.')
        ->and($list->items()->orderBy('position')->pluck('media_item_id')->all())->toBe([$films['matrix']->id, $films['amelie']->id])
        ->and($user->lists()->where('title', 'Removed List')->exists())->toBeFalse();
});

test('importing the same Letterboxd export twice adds nothing', function () {
    fakeLetterboxdSearch();
    $user = User::factory()->create();
    letterboxdFilms();
    $selection = new ImportSelection(['history', 'ratings', 'watchlist', 'favorites'], ['lists/late-night-picks.csv']);

    runImportFile($user, 'letterboxd', putZipFixture('letterboxd/export'), $selection);
    $plays = $user->plays()->count();

    $again = runImportFile($user, 'letterboxd', putZipFixture('letterboxd/export'), $selection);

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'favorites: 0', 'list items: 0')
        ->and($user->plays()->count())->toBe($plays);
});

test('a ZIP without the films marked watched is not a Letterboxd export', function () {
    $taskRun = runImportFile(User::factory()->create(), 'letterboxd', putZip(['user-profile.json' => '{}']), new ImportSelection(['history']));

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('The file is not a Letterboxd export.');
});
