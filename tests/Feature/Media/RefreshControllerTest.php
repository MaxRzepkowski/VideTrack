<?php

use App\Catalog\Relations\RelationStore;
use App\Catalog\TitleStore;
use App\Catalog\Wikidata\RelatedWorks;
use App\Jobs\EnrichShowSchedule;
use App\Jobs\RefreshTitleTies;
use App\Models\Company;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\RelatedTitle;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// A single stateful fake: the show's title follows $title by reference, so the
// same URL returns different data before and after upstream "changes" it. (One
// fake, because Http::fake() merges stubs — a second fake would not shadow the
// first for the same URL.)
function fakeShowTitled(string &$title): void
{
    Http::fake(function ($request) use (&$title) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/season/0') => Http::response(jsonFixture('tmdb/season0')),
            str_contains($url, '/season/1') => Http::response(jsonFixture('tmdb/season')),
            str_contains($url, '/season/2') => Http::response(jsonFixture('tmdb/season2')),
            default => tmdbShowAnswer([...jsonFixture('tmdb/tv'), 'name' => $title])($request),
        };
    });
}

test('a manual refresh bypasses the day-long provider cache and pulls fresh data', function () {
    $user = User::factory()->create();

    // First view persists and caches the show with its current title.
    $title = 'Original Title';
    fakeShowTitled($title);
    $show = app(TitleStore::class)->persist('show', '1399', 'en');

    expect($show->title)->toBe('Original Title');

    // Upstream renames it. Without a cache bypass the 24h-cached copy wins and
    // the refresh is a no-op.
    $title = 'Renamed Upstream';

    $this->actingAs($user)
        ->from('/show/1399')
        ->post('/media/refresh', ['type' => 'show', 'ref' => '1399'])
        ->assertRedirect('/show/1399');

    expect($show->fresh()->title)->toBe('Renamed Upstream');
});

test('a manual refresh also asks for the show\'s air times and the title\'s ties, in the background', function () {
    $user = User::factory()->create();
    $title = 'A Show';
    fakeShowTitled($title);
    $show = app(TitleStore::class)->persist('show', '1399', 'en');
    $show->update(['metadata' => [...($show->metadata ?? []), 'wikidataId' => 'Q23572']]);
    Bus::fake([EnrichShowSchedule::class, RefreshTitleTies::class]);

    $this->actingAs($user)->from('/show/1399')->post('/media/refresh', ['type' => 'show', 'ref' => '1399'])->assertRedirect('/show/1399');

    Bus::assertDispatched(EnrichShowSchedule::class);
    Bus::assertDispatched(RefreshTitleTies::class);
});

test('a title the outside source finds by its provider id queues a pass for its ties, though its provider names no item', function () {
    $title = 'A Show';
    fakeShowTitled($title);
    app(TitleStore::class)->persist('show', '1399', 'en');
    Bus::fake([EnrichShowSchedule::class, RefreshTitleTies::class]);

    $this->actingAs(User::factory()->create())->from('/show/1399')->post('/media/refresh', ['type' => 'show', 'ref' => '1399']);

    Bus::assertDispatched(RefreshTitleTies::class);
});

test('the queued pass asks the source afresh about the one title and lists its franchise again', function () {
    $film = MediaItem::factory()->movie()->create(['metadata' => ['wikidataId' => 'Q17738']]);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'ref' => '111']);
    app(RelationStore::class)->replace($film, []);
    $asked = [];
    Http::fake(function ($request) use (&$asked) {
        $query = (string) ($request->data()['query'] ?? '');
        $asked[] = match (true) {
            str_contains($query, 'wdt:P4969') => 'other media',
            str_contains($query, 'VALUES ?s { wd:Q17738 }') => 'title',
            str_contains($query, 'VALUES ?s { wd:Q462 }') => 'franchise',
            default => 'other',
        };

        return Http::response(['results' => ['bindings' => str_contains($query, 'wd:Q17738') ? [[
            's' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q17738'],
            'rel' => ['type' => 'literal', 'value' => 'franchise'],
            'o' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q462'],
        ]] : []]]);
    });

    // Asked a moment ago and answered from the cache since: the pass goes past both.
    app(RelatedWorks::class)->of(['Q17738']);
    RefreshTitleTies::dispatchSync($film->id);

    expect($asked)->toBe(['title', 'title', 'other media', 'franchise'])
        ->and(app(RelationStore::class)->franchisesOf($film))->toBe(['Q462'])
        ->and(RelatedTitle::query()->where('media_item_id', $film->id)->count())->toBe(0);
});

test('refreshing an episode refreshes its whole show tree', function () {
    $user = User::factory()->create();

    $title = 'Original Title';
    fakeShowTitled($title);
    app(TitleStore::class)->persist('show', '1399', 'en');

    $show = MediaItem::query()->where(['type' => 'show', 'provider_ref' => '1399'])->firstOrFail();
    $episode = MediaItem::query()->where('type', 'episode')->firstOrFail();

    $title = 'Renamed From Episode';

    // An episode ref nests season/episode numbers — climbing reaches the show
    // root, so the whole tree refreshes.
    $this->actingAs($user)
        ->from("/show/1399/season/1/episode/{$episode->number}")
        ->post('/media/refresh', ['type' => 'episode', 'ref' => $episode->provider_ref])
        ->assertRedirect();

    expect($show->fresh()->title)->toBe('Renamed From Episode');
});

test('the refresh endpoint rejects a malformed ref', function () {
    $this->actingAs(User::factory()->create())
        ->from('/discover')
        ->post('/media/refresh', ['type' => 'show', 'ref' => '../1399'])
        ->assertSessionHasErrors('ref');
});

test('a person refresh re-fetches the person from the provider', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $this->actingAs(User::factory()->create())
        ->from('/person/tmdb/6384')
        ->post('/person/tmdb/6384/refresh')
        ->assertRedirect('/person/tmdb/6384');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/person/6384'));
});

test('a company refresh re-fetches the company from the provider', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbCompanyPage(jsonFixture('igdb/company'))),
    ]);

    $this->actingAs(User::factory()->create())
        ->from('/company/igdb/70')
        ->post('/company/igdb/70/refresh')
        ->assertRedirect('/company/igdb/70');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/multiquery') && str_contains($request->body(), 'query companies'));
});

test('a collection refresh re-syncs its system list', function () {
    Http::fake([
        '*/collection/2344*' => Http::response([
            'id' => 2344,
            'name' => 'The Matrix Collection',
            'overview' => 'The Neo saga.',
            'backdrop_path' => '/cb.jpg',
            'parts' => [
                ['id' => 603, 'media_type' => 'movie', 'title' => 'The Matrix', 'poster_path' => '/m.jpg', 'release_date' => '1999-03-31'],
            ],
        ]),
        '*/movie/*' => Http::response([...jsonFixture('tmdb/movie'), 'belongs_to_collection' => ['id' => 2344, 'name' => 'The Matrix Collection']]),
    ]);

    $this->actingAs(User::factory()->create())
        ->from('/discover')
        ->post('/collection/tmdb/2344/refresh')
        ->assertRedirect();

    expect(MediaList::query()->where('system_key', 'collection:tmdb:2344')->exists())->toBeTrue();
});

test('a person refresh writes the stored row, not just the response cache', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $person = Person::factory()->create([
        'provider' => 'tmdb',
        'provider_ref' => '6384',
        'name' => 'Keanu Reeves',
        'profile_path' => '/gone.jpg',
    ]);

    $this->actingAs(User::factory()->create())
        ->from('/person/tmdb/6384')
        ->post('/person/tmdb/6384/refresh')
        ->assertRedirect('/person/tmdb/6384');

    expect($person->fresh()->profile_path)->toBe('/keanu.jpg');
});

test('a company refresh writes the stored row, not just the response cache', function () {
    enableGamesModule();
    Http::fake([
        '*/multiquery' => Http::response(igdbCompanyPage(jsonFixture('igdb/company'))),
    ]);

    $company = Company::factory()->create([
        'provider' => 'igdb',
        'kind' => 'company',
        'provider_ref' => '70',
        'name' => 'Nintendo',
        'logo_path' => 'https://images.igdb.com/igdb/image/upload/t_logo_med/gone.png',
    ]);

    $this->actingAs(User::factory()->create())
        ->from('/company/igdb/70')
        ->post('/company/igdb/70/refresh')
        ->assertRedirect('/company/igdb/70');

    expect($company->fresh()->logo_path)->toContain('cl9fnwljvm3jj4b2y8vg');
});

test('refreshing an entity nobody saved leaves the tables alone', function () {
    Http::fake(['*/person/6384*' => Http::response(jsonFixture('tmdb/person'))]);

    $this->actingAs(User::factory()->create())
        ->from('/person/tmdb/6384')
        ->post('/person/tmdb/6384/refresh')
        ->assertRedirect('/person/tmdb/6384');

    expect(Person::query()->count())->toBe(0);
});

test('refreshing a collection of a provider this instance does not have is a 404', function () {
    $this->actingAs(User::factory()->create())
        ->post('/collection/nonsense/1/refresh')
        ->assertNotFound();
});
