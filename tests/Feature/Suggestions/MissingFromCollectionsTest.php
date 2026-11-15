<?php

use App\Actions\Library\MarkWatched;
use App\Catalog\Sets\SetKind;
use App\Library\WatchedAt;
use App\Models\Play;
use App\Models\User;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Sources\MissingFromCollections;

function missingFromCollections(User $user): array
{
    return app(MissingFromCollections::class)->groups($user, 'en');
}

test('it asks for the collection of every film the user has finished', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '2']), WatchedAt::now());
    // Only on the shelf of someone else, and one never finished.
    app(MarkWatched::class)->execute(User::factory()->create(), filmOfCollection('20'), WatchedAt::now());
    filmOfCollection('30');

    $keys = app(MissingFromCollections::class)->wantedSets($user);

    expect($keys)->toHaveCount(1)
        ->and($keys[0]->kind)->toBe(SetKind::Collection)
        ->and($keys[0]->provider)->toBe('tmdb')
        ->and($keys[0]->ref)->toBe('10');
});

test('it puts forward the parts already out that the user has not seen', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    collectionSet('10', ['1' => '2001-01-01', '2' => '2003-01-01', '3' => '2005-01-01', '4' => today()->addYear()->toDateString(), '5' => null], 'The Saga');

    $groups = missingFromCollections($user);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->title)->toBe('The Saga')
        ->and($groups[0]->href)->toBe(route('collections.show', ['provider' => 'tmdb', 'ref' => '10'], false))
        // The part to come and the undated one count neither as missing nor into the total.
        ->and($groups[0]->caption)->toBe('1 of 3 watched')
        ->and(array_map(fn (Suggestion $suggestion): string => $suggestion->media->ref, $groups[0]->suggestions))->toBe(['2', '3'])
        ->and($groups[0]->suggestions[0]->reason)->toBe(['The Saga', '1 of 3 watched']);
});

test('a part the user has dropped or begun is not put forward', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    $dropped = filmOfCollection('10', ['provider_ref' => '2']);
    Play::factory()->dropped()->create(['user_id' => $user->id, 'media_item_id' => $dropped->id]);
    projectLibrary($user);
    collectionSet('10', ['1' => '2001-01-01', '2' => '2003-01-01', '3' => '2005-01-01']);

    $groups = missingFromCollections($user);

    expect(array_map(fn (Suggestion $suggestion): string => $suggestion->media->ref, $groups[0]->suggestions))->toBe(['3']);
});

test('a part without a poster is not put forward, though the collection lists it', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    $set = collectionSet('10', ['1' => '2001-01-01', '2' => '2003-01-01', '3' => '2005-01-01']);
    $set->titles()->where('ref', '2')->update(['card' => ['year' => 2003]]);

    $groups = missingFromCollections($user);

    expect(array_map(fn (Suggestion $suggestion): string => $suggestion->media->ref, $groups[0]->suggestions))->toBe(['3'])
        ->and($groups[0]->caption)->toBe('1 of 3 watched');
});

test('a finished collection and one not fetched yet have no group', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    app(MarkWatched::class)->execute($user, filmOfCollection('20', ['provider_ref' => '5']), WatchedAt::now());
    collectionSet('10', ['1' => '2001-01-01']);

    expect(missingFromCollections($user))->toBe([]);
});

test('the collection with the fewest parts left comes first', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    app(MarkWatched::class)->execute($user, filmOfCollection('20', ['provider_ref' => '5']), WatchedAt::now());
    collectionSet('10', ['1' => '2001-01-01', '2' => '2002-01-01', '3' => '2003-01-01'], 'Two left');
    collectionSet('20', ['5' => '2001-01-01', '6' => '2002-01-01'], 'One left');

    expect(array_map(fn ($group) => $group->title, missingFromCollections($user)))->toBe(['One left', 'Two left']);
});

test('a reader of another language reads the copy fetched in theirs', function () {
    $user = User::factory()->create();
    app(MarkWatched::class)->execute($user, filmOfCollection('10', ['provider_ref' => '1']), WatchedAt::now());
    collectionSet('10', ['1' => '2001-01-01', '2' => '2002-01-01'], 'Saga', 'pl');

    expect(missingFromCollections($user))->toBe([])
        ->and(app(MissingFromCollections::class)->groups($user, 'pl'))->toHaveCount(1);
});
