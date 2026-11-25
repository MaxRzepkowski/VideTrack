<?php

use App\Actions\Library\MarkWatched;
use App\Actions\Library\RateItem;
use App\Actions\Library\SetFavorite;
use App\Actions\Library\SyncListMembership;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\User;
use App\Notifications\MediaEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * The pages a library is read through most, each within a budget of
 * queries: measured on the library below, with a little room, and the same
 * however much of it there is. A page that starts asking a query per title,
 * per day or per card fails here before anyone feels it.
 */

/**
 * One more of everything a library holds: a film seen, rated and loved, one
 * of a collection whose next part is missing; a film on the Backlog out next
 * week; a show half watched with an episode ahead; a release notification.
 */
function growLibrary(User $user, int $round): void
{
    $seen = filmOfCollection("collection-{$round}", ['provider_ref' => "seen-{$round}", 'title' => "Seen {$round}", 'runtime' => 100]);
    collectionSet("collection-{$round}", ["seen-{$round}" => '2001-01-01', "missing-{$round}" => '2003-01-01'], "Saga {$round}");
    app(MarkWatched::class)->execute($user, $seen, WatchedAt::at(CarbonImmutable::parse('2026-10-01 20:00')->addDays($round)));
    app(RateItem::class)->execute($user, $seen, 8);
    app(SetFavorite::class)->execute($user, $seen, true);

    $ahead = MediaItem::factory()->movie()->create(['provider_ref' => "ahead-{$round}", 'title' => "Ahead {$round}", 'release_date' => today()->addDays(5 + $round)]);
    app(SyncListMembership::class)->execute($user, $ahead, [$user->watchlist()->id]);

    $show = makeShowTree([1 => ['2026-01-01', today()->addDays(3 + $round)->toDateString()]]);
    app(MarkWatched::class)->execute($user, episodeOf($show, 1, 1), WatchedAt::at(CarbonImmutable::parse('2026-10-02 20:00')->addDays($round)));

    $user->notify(new MediaEvent(premiereEvent("Premiere {$round}", "tmdb:premiere-{$round}")));
}

/** What a page drawn from a provider is told: a show, a person, search results and the Discover lists, from the fixtures. */
function fakeProviderPages(): void
{
    $results = jsonFixture('tmdb/search')['results'];

    Http::fake([
        '*/tv/1399/season/0*' => Http::response(jsonFixture('tmdb/season0')),
        '*/tv/1399/season/1*' => Http::response(jsonFixture('tmdb/season')),
        '*/tv/1399/season/2*' => Http::response(jsonFixture('tmdb/season2')),
        '*/tv/1399*' => tmdbShowAnswer(),
        '*/person/6384*' => Http::response(jsonFixture('tmdb/person')),
        '*/search/*' => Http::response(jsonFixture('tmdb/search')),
        '*/trending/*' => Http::response(['results' => $results]),
        '*/tv/popular*' => Http::response(['results' => $results]),
        '*/tv/on_the_air*' => Http::response(['results' => $results]),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        ...providerDictionaryFakes(),
    ]);
}

/**
 * One more episode seen of the show the provider fakes tell about, stored
 * with its tree: the page of a show being watched reads a state per episode.
 */
function watchMoreOfTheShow(User $user, int $round): void
{
    $show = MediaItem::query()->where('type', 'show')->where('provider_ref', '1399')->first()
        ?? showWithRefs('1399', [1 => array_map(fn (int $week): string => today()->subWeeks(10 - $week)->toDateString(), range(1, 8))]);

    app(MarkWatched::class)->execute($user, episodeOf($show, 1, $round), WatchedAt::at(CarbonImmutable::parse('2026-10-03 20:00')->addDays($round)));
}

/** Room over a page's measured count: a query more for a new feature passes, a query per row does not. */
const PAGE_QUERY_ROOM = 2;

test('a page reads a library within its query budget, whatever its size', function (int $measured, Closure $url, ?Closure $alsoGrow = null) {
    fakeProviderPages();
    $user = User::factory()->create();
    $grow = function (int $round) use ($user, $alsoGrow): void {
        growLibrary($user, $round);

        if ($alsoGrow !== null) {
            $alsoGrow($user, $round);
        }
    };

    foreach (range(1, 2) as $round) {
        $grow($round);
    }

    // Worked out afresh every time: a page that keeps what it worked out
    // (the suggestion tiles) would otherwise be counted reading it back.
    $visit = function () use ($user, $url): void {
        Cache::flush();
        test()->actingAs($user)->get($url($user))->assertOk();
    };

    // The first look may set up what a later one reads (a system list).
    $visit();

    $smaller = queriesRunBy($visit);

    foreach (range(3, 6) as $round) {
        $grow($round);
    }

    $larger = queriesRunBy($visit);
    expectSameQueries($smaller, $larger);

    expect(count($larger))->toBeLessThanOrEqual($measured + PAGE_QUERY_ROOM, implode("\n", array_map(fn (QueryExecuted $query): string => $query->sql, $larger)));
})->with([
    'the home page' => [19, fn (User $user): string => route('dashboard')],
    'the calendar' => [15, fn (User $user): string => route('calendar')],
    'the countdowns' => [15, fn (User $user): string => route('countdowns')],
    'the history' => [24, fn (User $user): string => route('users.history', ['user' => $user->username])],
    'the Backlog' => [18, fn (User $user): string => listPage($user->watchlist())],
    'the notifications' => [5, fn (User $user): string => route('notifications.index')],
    'the suggestions' => [24, fn (User $user): string => route('suggestions.index')],
    'a shelf of subjects' => [7, fn (User $user): string => route('suggestions.show', ['shelf' => 'missing-from-collections'])],
    'a show being watched' => [23, fn (User $user): string => route('show.details', ['ref' => '1399']), watchMoreOfTheShow(...)],
    'a person' => [11, fn (User $user): string => route('person.details', ['provider' => 'tmdb', 'ref' => '6384'])],
    'the search' => [11, fn (User $user): string => route('search', ['q' => 'matrix'])],
    'Discover' => [10, fn (User $user): string => route('discover', ['type' => 'show'])],
]);
