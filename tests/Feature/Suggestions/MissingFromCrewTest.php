<?php

use App\Catalog\Department;
use App\Catalog\Sets\SetKind;
use App\Models\Credit;
use App\Models\Person;
use App\Models\User;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Sources\MissingFromDirectors;
use App\Suggestions\Sources\MissingFromWriters;

/**
 * A director with three films the user has finished (refs 1 to 3).
 */
function regularDirector(User $user, string $name = 'Ada Director'): Person
{
    $person = Person::factory()->create(['name' => $name]);

    foreach (['1', '2', '3'] as $ref) {
        finishedFilmWith($user, $person, Department::Directing, 'Director', ['provider_ref' => $ref.$person->id]);
    }

    return $person;
}

/**
 * The director's filmography: the three films seen, then the given others.
 *
 * @param  list<array<string, mixed>>  $others
 */
function directorSet(Person $person, array $others): void
{
    filmographySet($person, [
        ['ref' => '1'.$person->id], ['ref' => '2'.$person->id], ['ref' => '3'.$person->id],
        ...$others,
    ]);
}

test('it asks for the filmography of everyone who directed enough of what the user finished', function () {
    $user = User::factory()->create();
    $regular = regularDirector($user);
    // Two films are not a habit; an assistant on three is not their director.
    $twice = Person::factory()->create();
    finishedFilmWith($user, $twice, Department::Directing, 'Director');
    finishedFilmWith($user, $twice, Department::Directing, 'Director');
    $assistant = Person::factory()->create();

    foreach (range(1, 3) as $film) {
        finishedFilmWith($user, $assistant, Department::Directing, 'First Assistant Director');
    }

    $keys = app(MissingFromDirectors::class)->wantedSets($user);

    expect($keys)->toHaveCount(1)
        ->and($keys[0]->kind)->toBe(SetKind::Filmography)
        ->and($keys[0]->ref)->toBe($regular->provider_ref);
});

test('it puts forward the director\'s unseen films, newest first, with how far through the user is', function () {
    $user = User::factory()->create();
    $person = regularDirector($user);
    directorSet($person, [
        ['ref' => '900', 'released' => '2015-01-01', 'title' => 'Older'],
        ['ref' => '901', 'released' => '2022-01-01', 'title' => 'Newer'],
    ]);

    $groups = app(MissingFromDirectors::class)->groups($user, 'en');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->title)->toBe('Ada Director')
        ->and($groups[0]->key)->toBe("tmdb-{$person->provider_ref}")
        ->and($groups[0]->href)->toBe(route('person.details', ['provider' => 'tmdb', 'ref' => $person->provider_ref], false))
        ->and($groups[0]->caption)->toBe('3 of 5 watched')
        ->and([$groups[0]->finished, $groups[0]->total])->toBe([3, 5])
        ->and($groups[0]->posterPath)->toBe('/photo.jpg')
        ->and(array_map(fn (Suggestion $suggestion): string => $suggestion->media->title, $groups[0]->suggestions))->toBe(['Newer', 'Older']);
});

test('only films they directed, already out and known widely enough count', function () {
    $user = User::factory()->create();
    $person = regularDirector($user);
    directorSet($person, [
        ['ref' => '900', 'title' => 'Counts'],
        ['ref' => '901', 'role' => 'Second Unit Director'],
        ['ref' => '902', 'department' => Department::Writing, 'role' => 'Writer'],
        ['ref' => '903', 'type' => 'show'],
        ['ref' => '904', 'votes' => config('tracker.suggestions.min_votes') - 1],
        ['ref' => '905', 'votes' => null],
        ['ref' => '906', 'released' => today()->addYear()->toDateString()],
        ['ref' => '907', 'released' => null],
    ]);

    $groups = app(MissingFromDirectors::class)->groups($user, 'en');

    expect(array_map(fn (Suggestion $suggestion): string => $suggestion->media->title, $groups[0]->suggestions))->toBe(['Counts'])
        ->and($groups[0]->caption)->toBe('3 of 4 watched');
});

test('a director the user has seen less than the set share of is not put forward', function () {
    $user = User::factory()->create();
    $person = regularDirector($user);
    // Three of seven: under half.
    directorSet($person, [['ref' => '900'], ['ref' => '901'], ['ref' => '902'], ['ref' => '903']]);

    expect(app(MissingFromDirectors::class)->groups($user, 'en'))->toBe([]);
});

test('a director with nothing left, or whose filmography is not fetched yet, has no group', function () {
    $user = User::factory()->create();
    directorSet(regularDirector($user, 'Seen It All'), []);
    regularDirector($user, 'Not Fetched');

    expect(app(MissingFromDirectors::class)->groups($user, 'en'))->toBe([]);
});

test('the director the user is furthest through comes first', function () {
    $user = User::factory()->create();
    directorSet(regularDirector($user, 'Half Way'), [['ref' => '900'], ['ref' => '901'], ['ref' => '902']]);
    directorSet(regularDirector($user, 'Nearly Done'), [['ref' => '910']]);

    expect(array_map(fn ($group) => $group->title, app(MissingFromDirectors::class)->groups($user, 'en')))->toBe(['Nearly Done', 'Half Way']);
});

test('screenwriters are told by the jobs that write the film, counted once a film', function () {
    $user = User::factory()->create();
    $writer = Person::factory()->create(['name' => 'Will Writer']);

    foreach (['1', '2', '3'] as $ref) {
        $film = finishedFilmWith($user, $writer, Department::Writing, 'Screenplay', ['provider_ref' => $ref]);
        // Credited twice on the same film: still one film.
        Credit::factory()->create(['media_item_id' => $film->id, 'person_id' => $writer->id, 'department' => Department::Writing, 'role' => 'Writer', 'order' => 1]);
    }

    // The author of the novels behind three films wrote no film.
    $novelist = Person::factory()->create();

    foreach (range(1, 3) as $film) {
        finishedFilmWith($user, $novelist, Department::Writing, 'Novel');
    }

    filmographySet($writer, [
        ['ref' => '1', 'department' => Department::Writing, 'role' => 'Screenplay'],
        ['ref' => '1', 'department' => Department::Writing, 'role' => 'Writer'],
        ['ref' => '2', 'department' => Department::Writing, 'role' => 'Screenplay'],
        ['ref' => '3', 'department' => Department::Writing, 'role' => 'Writer'],
        ['ref' => '900', 'department' => Department::Writing, 'role' => 'Screenplay', 'title' => 'Unseen'],
        ['ref' => '901', 'department' => Department::Writing, 'role' => 'Characters'],
    ]);

    $source = app(MissingFromWriters::class);
    $groups = $source->groups($user, 'en');

    expect($source->wantedSets($user))->toHaveCount(1)
        ->and($groups)->toHaveCount(1)
        ->and($groups[0]->caption)->toBe('3 of 4 watched')
        ->and(array_map(fn (Suggestion $suggestion): string => $suggestion->media->title, $groups[0]->suggestions))->toBe(['Unseen']);
});
