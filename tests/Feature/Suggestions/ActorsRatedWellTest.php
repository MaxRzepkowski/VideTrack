<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Catalog\Department;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Sources\ActorsRatedWell;

/**
 * Thirty films rated 6, so the user's own average sits near 6 whatever else the test rates.
 */
function ordinaryTaste(User $user): void
{
    foreach (range(1, 30) as $film) {
        $item = MediaItem::factory()->movie()->create();
        app(MarkWatched::class)->execute($user, $item, WatchedAt::now());
        app(RateItem::class)->execute($user, $item, 6);
    }
}

/**
 * An actor in `$films` finished films, each rated `$rating`, at the given place in the cast.
 */
function actorRated(User $user, string $name, int $films, int $rating, int $order = 0): Person
{
    $actor = Person::factory()->create(['name' => $name]);

    foreach (range(1, $films) as $film) {
        finishedFilmWith($user, $actor, Department::Acting, 'A Character', rating: $rating, order: $order);
    }

    return $actor;
}

test('an actor whose films the user rates well above their own average is asked for', function () {
    $user = User::factory()->create();
    ordinaryTaste($user);
    $liked = actorRated($user, 'Liked', films: 6, rating: 8);
    // Rated like everything else; too few films to tell; only ever deep in the cast.
    actorRated($user, 'Average', films: 6, rating: 6);
    actorRated($user, 'Two Hits', films: 2, rating: 10);
    actorRated($user, 'Cameo', films: 6, rating: 9, order: config('tracker.suggestions.actors.max_billing'));

    $keys = app(ActorsRatedWell::class)->wantedSets($user);

    expect(array_map(fn ($key) => $key->ref, $keys))->toBe([$liked->provider_ref]);
});

test('it puts forward their unseen films where they are near the top of the cast, not as themselves', function () {
    $user = User::factory()->create();
    ordinaryTaste($user);
    $actor = actorRated($user, 'Liked Actor', films: 6, rating: 8);
    $acting = ['department' => Department::Acting, 'role' => 'A Character', 'billing' => 0];
    filmographySet($actor, [
        [...$acting, 'ref' => '900', 'title' => 'Lead', 'released' => '2020-01-01'],
        [...$acting, 'ref' => '901', 'title' => 'Earlier lead', 'released' => '2010-01-01'],
        [...$acting, 'ref' => '902', 'billing' => config('tracker.suggestions.actors.max_billing')],
        [...$acting, 'ref' => '903', 'billing' => null],
        [...$acting, 'ref' => '904', 'role' => 'Self'],
        [...$acting, 'ref' => '905', 'role' => 'Liked Actor'],
        [...$acting, 'ref' => '906', 'department' => Department::Directing, 'role' => 'Director'],
    ]);

    $groups = app(ActorsRatedWell::class)->groups($user, 'en');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->title)->toBe('Liked Actor')
        ->and($groups[0]->caption)->toBe('Your average 8.0 across 6 movies')
        ->and(array_map(fn (Suggestion $suggestion): string => $suggestion->media->title, $groups[0]->suggestions))->toBe(['Lead', 'Earlier lead']);
});

test('the best liked actor comes first', function () {
    $user = User::factory()->create();
    ordinaryTaste($user);
    $acting = ['department' => Department::Acting, 'role' => 'A Character', 'billing' => 0];
    filmographySet(actorRated($user, 'Liked', films: 6, rating: 8), [[...$acting, 'ref' => '900']]);
    filmographySet(actorRated($user, 'Loved', films: 6, rating: 10), [[...$acting, 'ref' => '901']]);

    expect(array_map(fn ($group) => $group->title, app(ActorsRatedWell::class)->groups($user, 'en')))->toBe(['Loved', 'Liked']);
});

test('an account that has rated nothing gets no actors', function () {
    $user = User::factory()->create();

    expect(app(ActorsRatedWell::class)->wantedSets($user))->toBe([])
        ->and(app(ActorsRatedWell::class)->groups($user, 'en'))->toBe([]);
});
