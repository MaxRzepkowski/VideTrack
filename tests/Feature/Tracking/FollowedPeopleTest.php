<?php

use App\Catalog\MediaItemSummaries;
use App\Enums\FollowMode;
use App\Library\LibraryStates;
use App\Models\Follow;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use App\Tracking\Creators\PersonTitles;
use App\Tracking\Surfaces\CalendarDays;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function runFollowedPeople(): void
{
    runScheduledTask('refresh-followed-people');
}

function fakePersonWithUpcomingMovie(): void
{
    $person = jsonFixture('tmdb/person');

    $person['combined_credits']['cast'][] = [
        'id' => 603,
        'media_type' => 'movie',
        'title' => 'Upcoming Movie',
        'poster_path' => '/upcoming.jpg',
        'release_date' => today()->addDays(30)->toDateString(),
        'character' => 'Neo',
        'popularity' => 999.0,
    ];

    // A "Self" talk-show appearance in the same window must be filtered out.
    $person['combined_credits']['cast'][] = [
        'id' => 1399,
        'media_type' => 'tv',
        'name' => 'Some Talk Show',
        'poster_path' => '/talk.jpg',
        'first_air_date' => today()->addDays(10)->toDateString(),
        'character' => 'Self',
        'popularity' => 500.0,
    ];

    // A directing credit — outside an actor's main field, so only the "all
    // departments" scope picks it up (the fixture is known for Acting).
    $person['combined_credits']['crew'][] = [
        'id' => 550,
        'media_type' => 'movie',
        'title' => 'Directed Movie',
        'poster_path' => '/directed.jpg',
        'release_date' => today()->addDays(40)->toDateString(),
        'department' => 'Directing',
        'job' => 'Director',
        'popularity' => 800.0,
    ];

    $directedMovie = jsonFixture('tmdb/movie');
    $directedMovie['id'] = 550;

    Http::fake([
        '*/person/6384*' => Http::response($person),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*/movie/550*' => Http::response($directedMovie),
        '*' => Http::response(['status_message' => 'not found'], 404),
    ]);
}

/**
 * A film already in cinemas whose home release is still ahead — the case the
 * whole "outside cinemas" feature exists for, and the one a
 * future-premiere-only filter would silently drop.
 */
function fakePersonWithFilmStillInCinemas(?string $homeRelease): void
{
    $person = jsonFixture('tmdb/person');

    $person['combined_credits']['cast'][] = [
        'id' => 603,
        'media_type' => 'movie',
        'title' => 'Playing In Cinemas',
        'poster_path' => '/cinema.jpg',
        'release_date' => today()->subDays(50)->toDateString(),
        'character' => 'Neo',
        'popularity' => 999.0,
    ];

    $movie = jsonFixture('tmdb/movie');
    $movie['release_date'] = today()->subDays(50)->toDateString();
    $movie['release_dates']['results'] = [[
        'iso_3166_1' => 'US',
        'release_dates' => array_values(array_filter([
            ['certification' => 'R', 'release_date' => today()->subDays(50)->toDateString().'T00:00:00.000Z', 'type' => 3],
            $homeRelease === null
                ? null
                : ['certification' => '', 'release_date' => $homeRelease.'T00:00:00.000Z', 'type' => 4],
        ])),
    ]];

    Http::fake([
        '*/person/6384*' => Http::response($person),
        '*/movie/603*' => Http::response($movie),
        '*' => Http::response(['status_message' => 'not found'], 404),
    ]);
}

test('a film past its premiere is followed while its home release is ahead', function () {
    fakePersonWithFilmStillInCinemas(today()->addDays(20)->toDateString());

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow'])
        ->assertSuccessful();

    runFollowedPeople();

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->sole();

    expect($movie->home_release_date?->toDateString())->toBe(today()->addDays(20)->toDateString())
        ->and(Follow::query()->where('user_id', $user->id)->where('media_item_id', $movie->id)->exists())->toBeTrue();
});

test('a film with nothing left ahead is not followed', function () {
    // Opened 50 days ago and reached home 10 days ago: both moments are behind
    // us, so the grace window must not leave it parked on the follow list.
    fakePersonWithFilmStillInCinemas(today()->subDays(10)->toDateString());

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow'])
        ->assertSuccessful();

    runFollowedPeople();

    expect(Follow::query()->where('user_id', $user->id)->whereNotNull('media_item_id')->exists())->toBeFalse();
});

test('a followed person auto-follows their upcoming title and announces it once', function () {
    fakePersonWithUpcomingMovie();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow'])
        ->assertSuccessful();

    runFollowedPeople();

    $person = Person::query()->where('provider_ref', '6384')->firstOrFail();
    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->firstOrFail();

    $follow = $user->follows()->where('media_item_id', $movie->id)->firstOrFail();

    expect($follow->mode)->toBe(FollowMode::Follow)
        ->and($follow->viaPeople()->pluck('people.id')->all())->toBe([$person->id])
        // The Self appearance stayed out.
        ->and($user->follows()->whereNotNull('media_item_id')->count())->toBe(1)
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->firstOrFail()->data['event'])->toBe('person_new_title')
        ->and($user->notifications()->firstOrFail()->data['personName'])->toBe('Keanu Reeves');

    // Idempotent: a second run neither re-follows nor re-announces.
    runFollowedPeople();

    expect($user->follows()->whereNotNull('media_item_id')->count())->toBe(1)
        ->and($user->notifications()->count())->toBe(1);
});

test('every flavour of an "as themselves" credit stays off the calendar', function () {
    $person = jsonFixture('tmdb/person');

    // The one genuine acting credit that must survive.
    $person['combined_credits']['cast'][] = [
        'id' => 603,
        'media_type' => 'movie',
        'title' => 'Upcoming Movie',
        'poster_path' => '/upcoming.jpg',
        'release_date' => today()->addDays(30)->toDateString(),
        'character' => 'Neo',
        'popularity' => 999.0,
    ];

    // The four ways a self-appearance would sneak through an English-only,
    // case-sensitive "Self" check: lowercase, a pronoun variant, a localized
    // (Polish) phrasing, and the actor credited under their OWN name.
    $selfRoles = ['self', 'Himself', 'We własnej osobie', 'Keanu Reeves'];

    foreach ($selfRoles as $i => $role) {
        $person['combined_credits']['cast'][] = [
            'id' => 7000 + $i,
            'media_type' => 'tv',
            'name' => "Talk Show {$i}",
            'poster_path' => "/talk{$i}.jpg",
            'first_air_date' => today()->addDays(10)->toDateString(),
            'character' => $role,
            'popularity' => 500.0,
        ];
    }

    // A decoy that merely CONTAINS "self" as a substring must NOT be dropped.
    $person['combined_credits']['cast'][] = [
        'id' => 7100,
        'media_type' => 'movie',
        'title' => 'Selfish',
        'poster_path' => '/selfish.jpg',
        'release_date' => today()->addDays(20)->toDateString(),
        'character' => 'Selfish Man',
        'popularity' => 400.0,
    ];

    $selfish = jsonFixture('tmdb/movie');
    $selfish['id'] = 7100;

    Http::fake([
        '*/person/6384*' => Http::response($person),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*/movie/7100*' => Http::response($selfish),
        '*' => Http::response(['status_message' => 'not found'], 404),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    $refs = MediaItem::query()
        ->whereIn('id', $user->follows()->whereNotNull('media_item_id')->pluck('media_item_id'))
        ->pluck('provider_ref')
        ->sort()
        ->values()
        ->all();

    // Only the real acting role and the "Selfish Man" decoy — every genuine
    // self-appearance was filtered out.
    expect($refs)->toBe(['603', '7100']);
});

test('appearing as themselves disqualifies a title their other credits would admit', function () {
    // A documentary about the person that also credits them as its director:
    // the self-appearance must outweigh the directing credit.
    $documentary = [
        'id' => 550,
        'media_type' => 'movie',
        'title' => 'Documentary About Them',
        'poster_path' => '/doc.jpg',
        'release_date' => today()->addDays(30)->toDateString(),
        'popularity' => 800.0,
    ];

    $selfCredit = false;

    Http::fake([
        '*/person/6384*' => function () use (&$selfCredit, $documentary) {
            $person = jsonFixture('tmdb/person');
            $person['combined_credits']['crew'][] = $documentary + ['department' => 'Directing', 'job' => 'Director'];

            if ($selfCredit) {
                $person['combined_credits']['cast'][] = $documentary + ['character' => 'Keanu Reeves'];
            }

            return Http::response($person);
        },
        '*/movie/550*' => Http::response(array_merge(jsonFixture('tmdb/movie'), ['id' => 550])),
        '*' => Http::response(['status_message' => 'not found'], 404),
    ]);

    $user = User::factory()->create(['preferences' => ['person_follow_scope' => 'all']]);

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '550')->firstOrFail();
    $movie->update(['release_date' => today()->addDays(30)->toDateString()]);

    // Without the self credit it is a plain directing credit.
    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeTrue();

    $selfCredit = true;
    Cache::flush();

    runFollowedPeople();

    // With it, the row the directing credit created is withdrawn too.
    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeFalse();
});

test('the all-departments scope also follows credits outside the main field', function () {
    fakePersonWithUpcomingMovie();

    $user = User::factory()->create(['preferences' => ['person_follow_scope' => 'all']]);

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    $refs = MediaItem::query()
        ->whereIn('id', $user->follows()->whereNotNull('media_item_id')->pluck('media_item_id'))
        ->pluck('provider_ref')
        ->sort()
        ->values()
        ->all();

    expect($refs)->toBe(['550', '603']);
});

test('unfollowing the person sweeps their auto-follows but keeps manual ones', function () {
    fakePersonWithUpcomingMovie();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow'])
        ->assertSuccessful();

    runFollowedPeople();

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->firstOrFail();

    // The user also bell-follows another title by hand.
    $manual = MediaItem::factory()->movie()->create();
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $manual->id, 'mode' => FollowMode::Follow]);

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => null])
        ->assertSuccessful();

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeFalse()
        ->and($user->follows()->where('media_item_id', $manual->id)->exists())->toBeTrue()
        ->and($user->follows()->whereNotNull('person_id')->exists())->toBeFalse();
});

test('a manual bell click takes over an auto-follow', function () {
    fakePersonWithUpcomingMovie();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    // Muting the auto-followed title overrides it (sponsorship stays until
    // the person is unfollowed) — unfollowing the person then withdraws the
    // sponsor but must not resurrect or remove the mute itself.
    $this->actingAs($user)
        ->putJson(route('ui.media.follow.update', ['type' => 'movie', 'ref' => '603']), ['mode' => 'mute'])
        ->assertSuccessful();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => null]);

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->firstOrFail();
    $row = $user->follows()->where('media_item_id', $movie->id)->firstOrFail();

    expect($row->mode)->toBe(FollowMode::Mute)
        ->and($row->viaPeople()->count())->toBe(0);
});

/**
 * Fakes the person endpoint with a switchable upcoming credit — flipping the
 * flag simulates TMDB removing a mis-attributed casting between two sweeps
 * (re-registering Http::fake would not override the first matching stub).
 *
 * @return callable(bool): void toggle for the credit's presence
 */
function fakePersonWithRemovableCredit(): callable
{
    $present = true;

    Http::fake([
        '*/person/6384*' => function () use (&$present) {
            $person = jsonFixture('tmdb/person');

            if ($present) {
                $person['combined_credits']['cast'][] = [
                    'id' => 603,
                    'media_type' => 'movie',
                    'title' => 'Upcoming Movie',
                    'poster_path' => '/upcoming.jpg',
                    'release_date' => today()->addDays(30)->toDateString(),
                    'character' => 'Neo',
                    'popularity' => 999.0,
                ];
            }

            return Http::response($person);
        },
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*' => Http::response(['status_message' => 'not found'], 404),
    ]);

    return function (bool $value) use (&$present): void {
        $present = $value;
    };
}

test('a vanished credit withdraws the auto-follow on the next sweep', function () {
    $setCredit = fakePersonWithRemovableCredit();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->firstOrFail();

    // The details fixture (The Matrix) carries a past date — pin the item to
    // its upcoming premiere, as a genuinely unreleased title would be.
    $movie->update(['release_date' => today()->addDays(30)->toDateString()]);

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeTrue();

    // TMDB fixes the mis-attribution: the credit disappears from the person.
    // (Flush the provider response cache — a real sweep runs a day later.)
    $setCredit(false);
    Cache::flush();

    runFollowedPeople();

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeFalse()
        // The person themselves stays followed.
        ->and($user->follows()->whereNotNull('person_id')->exists())->toBeTrue();
});

test('a released sponsored title and a manual bell survive the vanished-credit sweep', function () {
    $setCredit = fakePersonWithRemovableCredit();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    // The sponsored title premieres (its date passes) and its credit vanishes.
    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->firstOrFail();
    $movie->update(['release_date' => today()->subDay()->toDateString()]);

    // A manual, provenance-free bell on another title must also survive.
    $manual = MediaItem::factory()->movie()->create(['release_date' => today()->addDays(10)->toDateString()]);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $manual->id, 'mode' => FollowMode::Follow]);

    $setCredit(false);
    Cache::flush();

    runFollowedPeople();

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeTrue()
        ->and($user->follows()->where('media_item_id', $manual->id)->exists())->toBeTrue();
});

/**
 * The same person, with the upcoming credit's DATE under control alongside its
 * presence — the two states a dateless sponsored title can be in.
 *
 * @return callable(bool, string|null): void toggle for presence + credit date
 */
function fakePersonWithDatelessCredit(): callable
{
    $present = true;
    $date = today()->addDays(30)->toDateString();

    // Id 550, not the fixture's own 603 — The Matrix sits in the base credits,
    // so a credit on 603 can never actually vanish from the person.
    $movie = jsonFixture('tmdb/movie');
    $movie['id'] = 550;

    Http::fake([
        '*/person/6384*' => function () use (&$present, &$date) {
            $person = jsonFixture('tmdb/person');

            if ($present) {
                $person['combined_credits']['cast'][] = [
                    'id' => 550,
                    'media_type' => 'movie',
                    'title' => 'Upcoming Movie',
                    'poster_path' => '/upcoming.jpg',
                    'release_date' => $date,
                    'character' => 'Neo',
                    'popularity' => 999.0,
                ];
            }

            return Http::response($person);
        },
        '*/movie/550*' => Http::response($movie),
        '*' => Http::response(['status_message' => 'not found'], 404),
    ]);

    return function (bool $isPresent, ?string $creditDate) use (&$present, &$date): void {
        $present = $isPresent;
        $date = $creditDate;
    };
}

// A bell row must not link to a 404: TMDB can delete a title outright,
// leaving the item nothing but its name and no date, and a sweep that skipped
// dateless items would keep its sponsorship forever.
test('a dateless sponsored title whose credit vanished is withdrawn', function () {
    $setCredit = fakePersonWithDatelessCredit();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '550')->firstOrFail();

    // The title is deleted at the provider: no date survives on the record and
    // the credit is gone from the person.
    $movie->update(['release_date' => null]);

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeTrue();

    $setCredit(false, null);
    Cache::flush();

    runFollowedPeople();

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeFalse()
        ->and($user->follows()->whereNotNull('person_id')->exists())->toBeTrue();
});

// The other half of the same rule: a premiere pushed back to "TBA" also leaves
// the item dateless, but the credit still stands — that row must survive.
test('a dateless sponsored title keeps its row while the credit still stands', function () {
    $setCredit = fakePersonWithDatelessCredit();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '550')->firstOrFail();
    $movie->update(['release_date' => null]);

    // The credit stays on the person's page, its date withdrawn to TBA — which
    // drops it out of the UPCOMING set the sweep matches against.
    $setCredit(true, '');
    Cache::flush();

    runFollowedPeople();

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeTrue();
});

test('lifting a mute restores the person-sponsored auto-follow', function () {
    fakePersonWithUpcomingMovie();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);

    runFollowedPeople();

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->firstOrFail();

    // The bell walks auto → mute → auto again; the round-trip must land back
    // on the sponsored auto-follow, not erase the row.
    $this->actingAs($user)
        ->putJson(route('ui.media.follow.update', ['type' => 'movie', 'ref' => '603']), ['mode' => 'mute'])
        ->assertSuccessful();

    $muted = $user->follows()->where('media_item_id', $movie->id)->firstOrFail();

    expect($muted->mode)->toBe(FollowMode::Mute)
        ->and($muted->viaPeople()->count())->toBe(1);

    $this->actingAs($user)
        ->putJson(route('ui.media.follow.update', ['type' => 'movie', 'ref' => '603']), ['mode' => null])
        ->assertSuccessful();

    $restored = $user->follows()->where('media_item_id', $movie->id)->firstOrFail();

    expect($restored->mode)->toBe(FollowMode::Follow)
        ->and($restored->viaPeople()->pluck('name')->all())->toBe(['Keanu Reeves']);

    // Clearing the bell on an UNsponsored exception still removes the row.
    $manual = MediaItem::factory()->movie()->create();
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $manual->id, 'mode' => FollowMode::Follow]);

    $this->actingAs($user)
        ->putJson(route('ui.media.follow.update', ['type' => $manual->type, 'ref' => $manual->provider_ref]), ['mode' => null])
        ->assertSuccessful();

    expect($user->follows()->where('media_item_id', $manual->id)->exists())->toBeFalse();
});

test('a title shared by two followed people survives unfollowing one of them', function () {
    $person = jsonFixture('tmdb/person');

    $upcoming = [
        'id' => 603,
        'media_type' => 'movie',
        'title' => 'Upcoming Movie',
        'poster_path' => '/upcoming.jpg',
        'release_date' => today()->addDays(30)->toDateString(),
        'character' => 'Lead',
        'popularity' => 999.0,
    ];

    $person['combined_credits']['cast'][] = $upcoming;

    $second = $person;
    $second['id'] = 287;
    $second['name'] = 'Brad Pitt';

    Http::fake([
        '*/person/6384*' => Http::response($person),
        '*/person/287*' => Http::response($second),
        '*/movie/603*' => Http::response(jsonFixture('tmdb/movie')),
        '*' => Http::response(['status_message' => 'not found'], 404),
    ]);

    $user = User::factory()->create();

    // Following both actors (the sync queue runs inline in tests) leaves ONE
    // follow row co-sponsored by both.
    $this->actingAs($user)->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => 'follow']);
    $this->actingAs($user)->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '287']), ['mode' => 'follow']);

    $movie = MediaItem::query()->where('type', 'movie')->where('provider_ref', '603')->firstOrFail();
    $follow = $user->follows()->where('media_item_id', $movie->id)->firstOrFail();

    // The persisted row carries the provider fixture's 1999 date — pull it
    // into the assertion window (the sponsorship logic under test does not
    // care which date the provider serves).
    $movie->update(['release_date' => today()->addDays(30)->toDateString()]);

    expect($follow->viaPeople()->pluck('name')->sort()->values()->all())->toBe(['Brad Pitt', 'Keanu Reeves']);

    $calendar = fn (): array => app(CalendarDays::class)->range(
        $user,
        today()->toDateString(),
        today()->addDays(60)->toDateString(),
    );

    expect($calendar()[0]->entries[0]->media->extra['followedVia'])->toBe('Keanu Reeves, Brad Pitt');

    // Unfollowing the FIRST sponsor keeps the title on the calendar while
    // Brad Pitt still backs it.
    $this->actingAs($user)->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '6384']), ['mode' => null]);

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeTrue()
        ->and($follow->refresh()->viaPeople()->pluck('name')->all())->toBe(['Brad Pitt'])
        ->and($calendar()[0]->entries[0]->media->extra['followedVia'])->toBe('Brad Pitt');

    // Withdrawing the last sponsor finally removes it.
    $this->actingAs($user)->putJson(route('ui.people.follow.update', ['provider' => 'tmdb', 'ref' => '287']), ['mode' => null]);

    expect($user->follows()->where('media_item_id', $movie->id)->exists())->toBeFalse()
        ->and($calendar())->toBe([]);
});

test('a followed book author auto-follows their upcoming book and announces it', function () {
    enableBooksModule();

    $author = jsonFixture('hardcover/author');

    // The fixture ships only published books — add one with a FUTURE date. It
    // arrives in its own slice: nobody has read it yet, so the reader ranking
    // of `books` would never reach it.
    $upcoming = $author['data']['books'][0];
    $upcoming['id'] = 888001;
    $upcoming['title'] = 'The Unfinished Tale';
    $upcoming['release_date'] = today()->addDays(45)->toDateString();
    $upcoming['release_year'] = (int) today()->addDays(45)->format('Y');
    $upcoming['users_count'] = 0;
    $author['data']['upcoming'] = [$upcoming];

    $details = jsonFixture('hardcover/book');
    $details['data']['books_by_pk']['id'] = 888001;
    $details['data']['books_by_pk']['title'] = 'The Unfinished Tale';
    $details['data']['books_by_pk']['release_date'] = $upcoming['release_date'];

    Http::fake([
        'api.hardcover.app/*' => function ($request) use ($author, $details) {
            $query = (string) data_get($request->data(), 'query');

            return match (true) {
                str_contains($query, 'query Author') => Http::response($author),
                str_contains($query, 'query Details') => Http::response($details),
                default => Http::response(['data' => []]),
            };
        },
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('ui.people.follow.update', ['provider' => 'hardcover', 'ref' => '132049']), ['mode' => 'follow'])
        ->assertSuccessful();

    $book = MediaItem::query()->where('type', 'book')->where('provider_ref', '888001')->firstOrFail();
    $row = $user->follows()->where('media_item_id', $book->id)->firstOrFail();

    expect($row->mode)->toBe(FollowMode::Follow)
        ->and($row->viaPeople()->pluck('name')->all())->toBe(['J.R.R. Tolkien'])
        // Published books never materialize — only the future one did.
        ->and($user->follows()->whereNotNull('media_item_id')->count())->toBe(1)
        ->and($user->notifications()->firstOrFail()->data['event'])->toBe('person_new_title')
        ->and($user->notifications()->firstOrFail()->data['media']['type'])->toBe('book');
});

test('the following page serves one newest-first grid with kinds and a type filter', function () {
    $user = User::factory()->create();

    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    $manual = MediaItem::factory()->movie()->create();
    $viaTitle = MediaItem::factory()->movie()->create();
    $mutedTitle = MediaItem::factory()->show()->create();

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $manual->id, 'mode' => FollowMode::Follow]);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $viaTitle->id, 'mode' => FollowMode::Follow])
        ->viaPeople()->attach($person->id);
    Follow::factory()->create(['user_id' => $user->id, 'person_id' => $person->id, 'mode' => FollowMode::Follow]);
    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $mutedTitle->id, 'mode' => FollowMode::Mute]);

    $this->actingAs($user)
        ->get(route('users.following', ['user' => $user->username]))
        ->assertInertia(fn ($page) => $page
            ->component('users/UserFollowing')
            ->has('entries', 4)
            ->where('pagination.total', 4)
            // Newest bell first.
            ->where('entries.0.kind', 'muted')
            ->where('entries.1.kind', 'person')
            ->where('entries.2.kind', 'via')
            ->where('entries.2.personName', 'Keanu Reeves')
            ->where('entries.3.kind', 'manual')
            ->has('types', 3));

    // The type filter narrows server-side; counts keep every type visible.
    $this->actingAs($user)
        ->get(route('users.following', ['user' => $user->username, 'type' => 'person']))
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('entries.0.kind', 'person')
            ->where('activeType', 'person')
            ->has('types', 3));

    // The kind filter isolates e.g. the muted set ("what did I silence?").
    $this->actingAs($user)
        ->get(route('users.following', ['user' => $user->username, 'kind' => 'muted']))
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('entries.0.kind', 'muted')
            ->where('activeKind', 'muted')
            ->has('kinds', 4));
});

test("another user's bell list is not found, an admin's view included", function () {
    $owner = User::factory()->create();

    foreach ([User::factory()->create(), User::factory()->admin()->create()] as $viewer) {
        $this->actingAs($viewer)
            ->get(route('users.following', ['user' => $owner->username]))
            ->assertNotFound();
    }
});

test('the library state names the person behind an auto-follow', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    $movie = MediaItem::factory()->movie()->create();

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Follow])
        ->viaPeople()->attach($person->id);

    $states = app(LibraryStates::class)->resolve($user, [MediaItemSummaries::summary($movie)]);

    expect($states["movie:{$movie->provider_ref}"]->follow)->toBe(FollowMode::Follow)
        ->and($states["movie:{$movie->provider_ref}"]->followVia)->toBe('Keanu Reeves');
});

test('calendar entries carry the followed person behind them', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['provider' => 'tmdb', 'provider_ref' => '6384', 'name' => 'Keanu Reeves']);
    $movie = MediaItem::factory()->movie()->create(['release_date' => today()->addDays(5)->toDateString()]);

    Follow::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id, 'mode' => FollowMode::Follow])
        ->viaPeople()->attach($person->id);

    $days = app(CalendarDays::class)->range(
        $user,
        today()->toDateString(),
        today()->addDays(30)->toDateString(),
    );

    expect($days)->toHaveCount(1)
        ->and($days[0]->entries[0]->media->extra['followedVia'])->toBe('Keanu Reeves');
});

test('every UI language knows how its credits say "as themselves"', function () {
    expect(array_keys(PersonTitles::SELF_ROLE_PHRASES))->toEqualCanonicalizing(config('tracker.supported_locales'));
});
