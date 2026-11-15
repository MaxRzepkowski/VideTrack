<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Catalog\Department;
use App\Catalog\Sets\SetKind;
use App\Library\WatchedAt;
use App\Models\CatalogSet;
use App\Models\CatalogSetTitle;
use App\Models\Credit;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Suggestions\SuggestionSources;

/**
 * A film stored as a part of a collection, the way its details name it.
 *
 * @param  array<string, mixed>  $attributes
 */
function filmOfCollection(string $collectionRef, array $attributes = []): MediaItem
{
    return MediaItem::factory()->movie()->create([
        'metadata' => ['collection' => ['ref' => $collectionRef, 'name' => 'A Collection']],
        ...$attributes,
    ]);
}

/**
 * A collection's set as its provider listed it: one part per ref, each out
 * on the given day.
 *
 * @param  array<string, string|null>  $parts  ref => release date
 */
function collectionSet(string $ref, array $parts, string $name = 'A Collection', string $locale = 'en'): CatalogSet
{
    $set = CatalogSet::factory()->create(['kind' => SetKind::Collection, 'ref' => $ref, 'name' => $name, 'locale' => $locale]);
    $position = 0;

    foreach ($parts as $partRef => $released) {
        CatalogSetTitle::factory()->create([
            'catalog_set_id' => $set->id,
            'position' => ++$position,
            'ref' => (string) $partRef,
            'release_date' => $released,
            'title' => "Part {$position}",
        ]);
    }

    return $set;
}

/**
 * A film the user has finished with the person credited on it, as the title's
 * stored credits name them; rated when a rating is given.
 */
function finishedFilmWith(User $user, Person $person, Department $department, string $role, array $attributes = [], ?int $rating = null, int $order = 0): MediaItem
{
    $film = MediaItem::factory()->movie()->create($attributes);
    Credit::factory()->create(['media_item_id' => $film->id, 'person_id' => $person->id, 'department' => $department, 'role' => $role, 'order' => $order]);
    app(MarkWatched::class)->execute($user, $film, WatchedAt::now());

    if ($rating !== null) {
        app(RateItem::class)->execute($user, $film, $rating);
    }

    return $film;
}

/**
 * A person's filmography as their provider listed it.
 *
 * @param  list<array{ref: string, released?: string|null, votes?: int|null, department?: Department, role?: string|null, billing?: int|null, type?: string, title?: string}>  $credits
 */
function filmographySet(Person $person, array $credits, string $locale = 'en'): CatalogSet
{
    $set = CatalogSet::factory()->create(['kind' => SetKind::Filmography, 'provider' => $person->provider, 'ref' => $person->provider_ref, 'name' => $person->name, 'poster_path' => '/photo.jpg', 'locale' => $locale]);

    foreach ($credits as $position => $credit) {
        CatalogSetTitle::factory()->create([
            'catalog_set_id' => $set->id,
            'position' => $position + 1,
            'type' => $credit['type'] ?? 'movie',
            'ref' => $credit['ref'],
            'title' => $credit['title'] ?? "Film {$credit['ref']}",
            'release_date' => array_key_exists('released', $credit) ? $credit['released'] : '2010-01-01',
            'votes' => array_key_exists('votes', $credit) ? $credit['votes'] : 5000,
            'department' => $credit['department'] ?? Department::Directing,
            'role' => array_key_exists('role', $credit) ? $credit['role'] : 'Director',
            'billing' => $credit['billing'] ?? null,
        ]);
    }

    return $set;
}

/**
 * Leaves the instance with only these suggestion shelves, so a test of the
 * set refresh counts the sets it set up and not every chart the rest ask for.
 *
 * @param  list<string>  $codes
 */
function onlySuggestionSources(array $codes): void
{
    $all = app(SuggestionSources::class);
    $kept = new SuggestionSources(app());

    foreach ($codes as $code) {
        $kept->register($code, $all->get($code)::class);
    }

    app()->instance(SuggestionSources::class, $kept);
}
