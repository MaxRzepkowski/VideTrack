<?php

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * How much of a followed creator's upcoming work each follower gets: ten
 * titles at most, soonest first, counted within that follower's own scope —
 * so two followers of one person can be backing different titles.
 */

// Each title is a provider call: enough of them to wait on the request
// limiter's window, which a frozen clock never opens.
beforeEach(fn () => $this->travelBack());

/**
 * A TMDB person whose credits are the given upcoming films: `[id, department]`,
 * soonest first, a day apart. Each film's own page answers under its id.
 *
 * @param  list<array{int, string}>  $films
 */
function fakeProlificPerson(array $films, string $knownFor = 'Acting', bool $filmPagesGone = false): void
{
    $cast = [];
    $crew = [];

    foreach ($films as $index => [$id, $department]) {
        $credit = [
            'id' => $id, 'media_type' => 'movie', 'title' => "Film {$id}", 'poster_path' => "/{$id}.jpg",
            'release_date' => today()->addDays(10 + $index)->toDateString(), 'popularity' => 50.0,
        ];

        if ($department === 'Acting') {
            $cast[] = [...$credit, 'character' => "Role {$id}"];
        } else {
            $crew[] = [...$credit, 'department' => $department, 'job' => $department === 'Directing' ? 'Director' : 'Writer'];
        }
    }

    Http::fake(function (Request $request) use ($cast, $crew, $knownFor, $filmPagesGone) {
        $url = $request->url();

        if (str_contains($url, '/person/6384')) {
            $person = jsonFixture('tmdb/person');
            $person['known_for_department'] = $knownFor;
            $person['combined_credits'] = ['cast' => $cast, 'crew' => $crew];

            return Http::response($person);
        }

        if (preg_match('#/movie/(\d+)#', $url, $match) === 1) {
            return $filmPagesGone ? Http::response(['status_message' => 'not found'], 404) : Http::response([...jsonFixture('tmdb/movie'), 'id' => (int) $match[1], 'title' => "Film {$match[1]}"]);
        }

        foreach (providerDictionaryFakes() as $pattern => $response) {
            if (Str::is($pattern, $url)) {
                return $response;
            }
        }

        return Http::response(['status_message' => 'not found'], 404);
    });
}

function followPerson(User $user): void
{
    test()->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow'])
        ->assertSuccessful();
}

/** @return list<string> the refs of the films the user's bells back, in id order */
function backedFilms(User $user): array
{
    return MediaItem::query()
        ->whereIn('id', $user->follows()->whereNotNull('media_item_id')->pluck('media_item_id'))
        ->orderBy('provider_ref')
        ->pluck('provider_ref')
        ->all();
}

test('a follower gets the ten soonest titles of a prolific person, and only those are fetched', function () {
    fakeProlificPerson(array_map(fn (int $id): array => [$id, 'Acting'], range(2001, 2014)));
    $user = User::factory()->create();

    followPerson($user);

    expect(backedFilms($user))->toBe(array_map('strval', range(2001, 2010)));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/movie/2011'));
});

test('two followers with different scopes each get their own first ten', function () {
    fakeProlificPerson([
        ...array_map(fn (int $id): array => [$id, 'Directing'], range(3001, 3008)),
        ...array_map(fn (int $id): array => [$id, 'Acting'], range(3101, 3108)),
    ]);
    $mainField = User::factory()->create(['preferences' => ['person_follow_scope' => 'known_for']]);
    $everything = User::factory()->create(['preferences' => ['person_follow_scope' => 'all']]);

    followPerson($mainField);
    followPerson($everything);

    expect(backedFilms($mainField))->toBe(array_map('strval', range(3101, 3108)))
        ->and(backedFilms($everything))->toBe([...array_map('strval', range(3001, 3008)), '3101', '3102']);
});

test('a title credited in two departments reaches the follower of either', function () {
    // Acting first: keeping one credit per title would keep this one and lose
    // the directing credit a director's follower is after.
    fakeProlificPerson([[4001, 'Acting'], [4001, 'Directing']], knownFor: 'Directing');
    $user = User::factory()->create(['preferences' => ['person_follow_scope' => 'known_for']]);

    followPerson($user);

    expect(backedFilms($user))->toBe(['4001']);
});

test("a game company's follower gets the games of the role they chose", function (string $scope, array $expected) {
    enableGamesModule();
    $soon = today()->addDays(20)->getTimestamp();
    $games = [
        [5001, 'developer' => true, 'publisher' => false],
        [5002, 'developer' => false, 'publisher' => true],
    ];

    Http::fake(function (Request $request) use ($games, $soon) {
        $url = $request->url();
        $body = $request->body();

        if (str_contains($url, 'id.twitch.tv')) {
            return Http::response(['access_token' => 'testing-token', 'expires_in' => 5000000]);
        }

        if (str_ends_with($url, '/companies')) {
            return Http::response(jsonFixture('igdb/company'));
        }

        if (str_ends_with($url, '/games') && str_contains($body, 'involved_companies.company = 70')) {
            // The upcoming front lists both games; the rated back catalogue nothing.
            return Http::response(str_contains($body, 'first_release_date >') ? array_map(fn (array $game): array => [
                'id' => $game[0], 'name' => "Game {$game[0]}", 'first_release_date' => $soon, 'game_type' => 0,
                'involved_companies' => [['company' => 70, 'developer' => $game['developer'], 'publisher' => $game['publisher']]],
            ], $games) : []);
        }

        if (str_ends_with($url, '/multiquery') && preg_match('/where id = (\d+);/', $body, $match) === 1) {
            return Http::response(igdbGamePage([[...jsonFixture('igdb/game')[0], 'id' => (int) $match[1], 'name' => "Game {$match[1]}", 'first_release_date' => $soon]]));
        }

        return Http::response([]);
    });

    $user = User::factory()->create(['preferences' => ['company_follow_scope' => $scope]]);

    $this->actingAs($user)
        ->putJson(route('ui.companies.follow.update', ['provider' => 'igdb', 'ref' => '70']), ['mode' => 'follow'])
        ->assertSuccessful();

    $backed = MediaItem::query()
        ->whereIn('id', $user->follows()->whereNotNull('media_item_id')->pluck('media_item_id'))
        ->orderBy('provider_ref')
        ->pluck('provider_ref')
        ->all();

    expect($backed)->toBe($expected);
})->with([
    'developed' => ['developed', ['5001']],
    'published' => ['published', ['5002']],
    'both' => ['both', ['5001', '5002']],
]);

test('a sweep stops fetching once the titles it tried keep failing', function () {
    // Every film page gone: no fetch ever yields a title to back.
    fakeProlificPerson(array_map(fn (int $id): array => [$id, 'Acting'], range(6001, 6030)), filmPagesGone: true);
    $user = User::factory()->create();

    followPerson($user);

    $fetched = collect(Http::recorded())
        ->map(fn (array $pair): ?string => preg_match('#/movie/(\d+)#', $pair[0]->url(), $match) === 1 ? $match[1] : null)
        ->filter()
        ->unique();

    expect(backedFilms($user))->toBe([])
        ->and($fetched->values()->all())->toBe(array_map('strval', range(6001, 6020)));
});
