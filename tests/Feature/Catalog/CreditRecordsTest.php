<?php

use App\Catalog\Creators\CreditRecords;
use App\Catalog\Creators\PersonRecords;
use App\Catalog\Data\CreditData;
use App\Catalog\Department;
use App\Catalog\TitleStore;
use App\Models\Credit;
use App\Models\MediaItem;
use App\Models\Person;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function fakeMovie(): void
{
    Http::fake(['*/movie/603*' => Http::response(jsonFixture('tmdb/movie'))]);
}

function fakeShowForCredits(): void
{
    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
    ]);
}

test('persisting a movie stores its cast and crew', function () {
    Http::fake(providerDictionaryFakes());

    fakeMovie();

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');

    // Fixture: two cast members, three crew rows over two people (Lana is
    // credited twice — director and writer).
    expect(Credit::where('media_item_id', $movie->id)->count())->toBe(5)
        ->and(Person::count())->toBe(4);

    $neo = Credit::query()
        ->where('media_item_id', $movie->id)
        ->where('department', 'acting')
        ->where('role', 'Neo')
        ->firstOrFail();

    expect($neo->order)->toBe(0)
        ->and($neo->episode_count)->toBeNull()
        ->and($neo->person->name)->toBe('Keanu Reeves')
        ->and($neo->person->provider_ref)->toBe('6384');
});

test('a person created from a credit costs no provider request', function () {
    Http::fake(providerDictionaryFakes());

    fakeMovie();

    app(TitleStore::class)->persist('movie', '603', 'en');

    // A cast of dozens must never turn into a page fetch per actor: the credit
    // line already carries the name and the portrait.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/person/'));

    expect(Person::count())->toBe(4);
});

test('a show credit keeps the episode count of its aggregate credits', function () {
    Http::fake(providerDictionaryFakes());

    fakeShowForCredits();

    $show = app(TitleStore::class)->persist('show', '1399', 'en');

    $tyrion = Credit::query()
        ->where('media_item_id', $show->id)
        ->where('role', 'Tyrion Lannister')
        ->firstOrFail();

    expect($tyrion->episode_count)->toBe(67);
});

test('crew outside the core departments is left out', function () {
    Http::fake(providerDictionaryFakes());

    fakeShowForCredits();

    $show = app(TitleStore::class)->persist('show', '1399', 'en');

    // The fixture's only crew member is an executive producer (Production).
    expect(Credit::where('media_item_id', $show->id)->count())->toBe(3)
        ->and(Credit::where('media_item_id', $show->id)->where('department', 'production')->exists())->toBeFalse();
});

test('the cast is capped so one title cannot flood the database', function () {
    $data = jsonFixture('tmdb/movie');

    $data['credits']['cast'] = array_map(fn (int $i): array => [
        'id' => 1000 + $i,
        'name' => "Extra {$i}",
        'character' => "Bystander {$i}",
        'profile_path' => null,
        'order' => $i,
    ], range(0, 49));

    Http::fake(['*/movie/603*' => Http::response($data), ...providerDictionaryFakes()]);

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');

    $cast = Credit::query()
        ->where('media_item_id', $movie->id)
        ->where('department', 'acting')
        ->get();

    expect($cast)->toHaveCount(CreditRecords::MAX_CAST)
        // Capped by billing order, not by the order the payload happened to
        // arrive in.
        ->and($cast->max('order'))->toBe(CreditRecords::MAX_CAST - 1);
});

test('the crew is capped the same way', function () {
    $data = jsonFixture('tmdb/movie');

    $data['credits']['crew'] = array_map(fn (int $i): array => [
        'id' => 2000 + $i,
        'name' => "Writer {$i}",
        'job' => 'Writer',
        'department' => 'Writing',
        'profile_path' => null,
    ], range(1, CreditRecords::MAX_CREW + 10));

    Http::fake(['*/movie/603*' => Http::response($data), ...providerDictionaryFakes()]);

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');

    expect(Credit::query()->where('media_item_id', $movie->id)->where('department', 'writing')->count())->toBe(CreditRecords::MAX_CREW);
});

/**
 * A mutable movie payload: Http::fake() MERGES stubs rather than replacing
 * them, so a second fake for the same URL never wins. The reference lets a
 * test change what the provider answers between two persists.
 *
 * @param  array<string, mixed>  $payload
 */
function fakeMutableMovie(array &$payload): void
{
    // `use (&…)`, not an arrow function: that one would capture the array by
    // value and freeze the first answer forever.
    Http::fake(function ($request) use (&$payload) {
        return str_contains($request->url(), '/movie/603')
            ? Http::response($payload)
            : Http::response([]);
    });
}

test('refreshing a title neither duplicates credits nor keeps withdrawn ones', function () {
    $payload = jsonFixture('tmdb/movie');
    fakeMutableMovie($payload);

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');

    expect(Credit::where('media_item_id', $movie->id)->count())->toBe(5);

    array_pop($payload['credits']['cast']);

    // The provider caches its own responses; without this the refresh would
    // replay the payload the first persist already stored.
    Cache::flush();

    app(TitleStore::class)->refresh($movie, 'en');

    expect(Credit::where('media_item_id', $movie->id)->count())->toBe(4)
        ->and(Credit::where('media_item_id', $movie->id)->where('role', 'Morpheus')->exists())->toBeFalse();
});

test('an empty payload leaves the stored cast alone', function () {
    $payload = jsonFixture('tmdb/movie');
    fakeMutableMovie($payload);

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');

    $payload['credits'] = ['cast' => [], 'crew' => []];
    Cache::flush();

    app(TitleStore::class)->refresh($movie, 'en');

    // A title does not lose its whole cast; a thin response is an outage, and
    // wiping the fallback exactly when the provider is shaky is the one thing
    // this must not do.
    expect(Credit::where('media_item_id', $movie->id)->count())->toBe(5);
});

test('a credit refreshes an existing person row without blanking what it knows', function () {
    Http::fake(providerDictionaryFakes());

    $person = Person::factory()->create([
        'provider' => 'tmdb',
        'provider_ref' => '6384',
        'name' => 'Keanu Reeves',
        'profile_path' => '/old.jpg',
        'biography' => 'Stored by an earlier page fetch.',
    ]);

    fakeMovie();

    app(TitleStore::class)->persist('movie', '603', 'en');

    expect($person->refresh()->biography)->toBe('Stored by an earlier page fetch.')
        ->and(Person::where('provider_ref', '6384')->count())->toBe(1);
});

test('credits go away with their title', function () {
    Http::fake(providerDictionaryFakes());

    fakeMovie();

    $movie = app(TitleStore::class)->persist('movie', '603', 'en');

    MediaItem::query()->whereKey($movie->id)->delete();

    expect(Credit::count())->toBe(0);
});

test('a person is created straight from a credit line', function () {
    $person = app(PersonRecords::class)->fromCredit(new CreditData(
        personRef: '287',
        provider: 'tmdb',
        name: 'Brad Pitt',
        profilePath: '/brad.jpg',
        department: Department::Acting,
        role: 'Tyler Durden',
    ));

    expect($person->exists)->toBeTrue()
        ->and($person->name)->toBe('Brad Pitt')
        ->and($person->profile_path)->toBe('/brad.jpg');

    Http::assertNothingSent();
});
