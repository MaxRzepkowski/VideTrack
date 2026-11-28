<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\RedirectAttempts;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Tmdb\TmdbAccountSource;
use App\Integrations\Tmdb\TmdbService;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/*
 * A TMDB account is exercised on the shapes of TMDB's v3 reference
 * (fixtures in tests/Fixtures/transfer/tmdb, see the README there) — never live.
 */

beforeEach(fn () => Http::preventStrayRequests());

/** Starts a trip to TMDB as $user; the address TMDB is told to send them back to. */
function tmdbReturnAddress(User $user): string
{
    $url = (string) test()->actingAs($user)->postJson(route('settings.connections.redirect.begin', TmdbService::CODE))->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return is_string($query['redirect_to'] ?? null) ? $query['redirect_to'] : throw new LogicException('A trip names its way back.');
}

function tmdbConnection(User $user): ServiceConnection
{
    return activeConnection($user, TmdbService::CODE, ['session_id' => 'session-of-alex'], ['settings' => ['account_id' => '548']]);
}

/**
 * TMDB answering the fixtures' account; every listing not named is empty.
 *
 * @param  array<string, mixed>  $more
 */
function fakeTmdbAccount(array $more = []): void
{
    $fixtures = [
        'api.themoviedb.org/3/account/548/watchlist/movies*' => Http::response(jsonFixture('transfer/tmdb/watchlist-movies')),
        'api.themoviedb.org/3/account/548/favorite/tv*' => Http::response(jsonFixture('transfer/tmdb/favorite-tv')),
        'api.themoviedb.org/3/account/548/rated/movies*' => Http::response(jsonFixture('transfer/tmdb/rated-movies')),
        'api.themoviedb.org/3/account/548/rated/tv/episodes*' => Http::response(jsonFixture('transfer/tmdb/rated-tv-episodes')),
        'api.themoviedb.org/3/account/548/lists*' => Http::response(jsonFixture('transfer/tmdb/lists')),
        'api.themoviedb.org/3/list/8221116*' => Http::response(jsonFixture('transfer/tmdb/list')),
        'api.themoviedb.org/3/list/120174*' => Http::response(['success' => false, 'status_code' => 39, 'status_message' => 'This resource is private.'], 401),
    ];

    // A test's own answer for a listing replaces the fixture's, in its place,
    // before the catch-all for every other listing.
    Http::fake([
        ...array_replace($fixtures, $more),
        'api.themoviedb.org/3/account/548/*' => Http::response(jsonFixture('transfer/tmdb/empty')),
    ]);
}

/**
 * @return array<string, MediaItem>
 */
function tmdbAccountCatalogue(): array
{
    return [
        'chronicle' => MediaItem::factory()->movie()->create(['provider_ref' => '76726']),
        'lotr' => MediaItem::factory()->movie()->create(['provider_ref' => '120']),
        'bigBang' => showWithRefs('1418', [7 => ['2013-09-26', '2013-09-26', '2013-10-03', '2013-10-10', '2013-10-17']]),
    ];
}

test('the integrations page offers TMDB with a trip to themoviedb.org', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.integrations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.accounts', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === TmdbAccountSource::CODE
                && $source['connect']['method'] === 'redirect'
                && $source['connect']['name'] === TmdbService::NAME)));
});

test('connecting sends the user to approve a request token and comes back with the state on the address', function () {
    Http::fake(['api.themoviedb.org/3/authentication/token/new*' => Http::response(['success' => true, 'expires_at' => '2026-10-10 13:00:00 UTC', 'request_token' => 'token-of-alex'])]);

    $url = (string) $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.redirect.begin', TmdbService::CODE))
        ->assertOk()
        ->json('url');

    expect($url)->toStartWith('https://www.themoviedb.org/authenticate/token-of-alex?redirect_to=');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $back = is_string($query['redirect_to'] ?? null) ? $query['redirect_to'] : '';

    // The state rides in the path, which TMDB leaves alone whatever query it adds.
    expect($back)->toMatch('#^'.preg_quote(route('settings.connections.callback', TmdbService::CODE), '#').'/[A-Za-z0-9]+$#');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/authentication/token/new')
        && $request->header('User-Agent') === [config('tracker.http.user_agent')]);
});

test('an approved token becomes a session and a connection named after the account, back on the TMDB import', function () {
    $user = User::factory()->create();
    Http::fake([
        'api.themoviedb.org/3/authentication/token/new*' => Http::response(['success' => true, 'request_token' => 'token-of-alex']),
        'api.themoviedb.org/3/authentication/session/new*' => Http::response(['success' => true, 'session_id' => 'session-of-alex']),
        'api.themoviedb.org/3/account?*' => Http::response(['id' => 548, 'username' => 'alex', 'include_adult' => false]),
    ]);

    $back = tmdbReturnAddress($user);

    // TMDB's own query on the way back names another token: the kept one is the one exchanged.
    $this->get($back.'?request_token=token-of-someone-else&approved=true')
        ->assertRedirect(route('settings.integrations.index', ['source' => TmdbAccountSource::CODE]));

    $connection = $user->serviceConnections()->sole();

    expect($connection->credential('session_id'))->toBe('session-of-alex')
        ->and($connection->setting('account_id'))->toBe('548')
        ->and($connection->account_label)->toBe('alex');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/authentication/session/new')
        && $request['request_token'] === 'token-of-alex');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/3/account?') && $request['session_id'] === 'session-of-alex');
});

test('a token the user turned down makes no connection, and the page says why', function () {
    $user = User::factory()->create();
    Http::fake([
        'api.themoviedb.org/3/authentication/token/new*' => Http::response(['success' => true, 'request_token' => 'token-of-alex']),
        'api.themoviedb.org/3/authentication/session/new*' => Http::response(['success' => false, 'status_code' => 17, 'status_message' => 'Session denied.'], 401),
    ]);

    $back = tmdbReturnAddress($user);

    $this->get($back.'?request_token=token-of-alex&denied=true')
        ->assertSessionHasErrors([RedirectAttempts::errorKey(TmdbService::CODE) => 'The connection was not approved on TMDB.']);

    expect($user->serviceConnections()->exists())->toBeFalse();
});

test('the account comes over: the watchlist, favorites, ratings and public lists', function () {
    $items = tmdbAccountCatalogue();
    $user = User::factory()->create();
    fakeTmdbAccount();

    $taskRun = runConnectionImport(tmdbConnection($user), TmdbAccountSource::CODE);

    $episode = MediaItem::query()->where('type', 'episode')->where('number', 5)->sole();

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['TMDB list "Kept To Myself": TMDB shows only public lists to other apps. Make it public on TMDB to import it.'])
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$items['chronicle']->id])
        ->and($user->favorites()->pluck('media_item_id')->all())->toBe([$items['bigBang']->id])
        // 8.5 of ten in halves, to the nearest whole point.
        ->and($user->ratings()->where('media_item_id', $items['lotr']->id)->value('rating'))->toBe(9)
        ->and($user->ratings()->where('media_item_id', $episode->id)->value('rating'))->toBe(8)
        ->and($user->lists()->where('title', 'Test List')->sole()->items()->pluck('media_item_id')->sort()->values()->all())
        ->toBe(collect([$items['chronicle']->id, $items['bigBang']->id])->sort()->values()->all())
        ->and($user->lists()->where('title', 'Kept To Myself')->exists())->toBeFalse();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/account/548/watchlist/movies') && $request['session_id'] === 'session-of-alex');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/list/') && isset($request['session_id']));
});

test('reading the same account again adds nothing', function () {
    tmdbAccountCatalogue();
    $connection = tmdbConnection(User::factory()->create());
    fakeTmdbAccount();

    runConnectionImport($connection, TmdbAccountSource::CODE);
    $again = runConnectionImport($connection, TmdbAccountSource::CODE);

    expect($again->summary)->toContain('ratings: 0', 'list items: 0', 'favorites: 0');
});

test('a listing longer than a page is read page by page', function () {
    tmdbAccountCatalogue();
    $connection = tmdbConnection(User::factory()->create());
    fakeTmdbAccount([
        'api.themoviedb.org/3/account/548/watchlist/movies*' => fn (Request $request) => Http::response(
            ['page' => (int) $request['page'], 'results' => [], 'total_pages' => 3, 'total_results' => 0],
        ),
    ]);

    runConnectionImport($connection, TmdbAccountSource::CODE);

    expect(requestsTo('/account/548/watchlist/movies'))->toBe(3);
});

test('a session TMDB no longer honours expires the connection', function () {
    $connection = tmdbConnection(User::factory()->create());
    Http::fake(['api.themoviedb.org/3/*' => Http::response(['success' => false, 'status_code' => 3, 'status_message' => 'Authentication failed.'], 401)]);

    $taskRun = runConnectionImport($connection, TmdbAccountSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toContain('TMDB stopped accepting this connection. Connect your account again.')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired);
});

test('disconnecting deletes the session at TMDB', function () {
    Http::fake(['api.themoviedb.org/3/authentication/session*' => Http::response(['success' => true])]);
    $user = User::factory()->create();
    $connection = tmdbConnection($user);

    $this->actingAs($user)->delete(route('settings.connections.destroy', $connection))->assertRedirect();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/authentication/session')
        && $request['session_id'] === 'session-of-alex');
});

test('an instance key TMDB refuses ends the run without expiring the connection', function () {
    $connection = tmdbConnection(User::factory()->create());
    Http::fake(['api.themoviedb.org/3/*' => Http::response(['success' => false, 'status_code' => 7, 'status_message' => 'Invalid API key: You must be granted a valid key.'], 401)]);

    $taskRun = runConnectionImport($connection, TmdbAccountSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe("TMDB did not accept this instance's API key.")
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});

test('a list gone from TMDB stays out with a word, the run goes on', function () {
    tmdbAccountCatalogue();
    $user = User::factory()->create();
    fakeTmdbAccount(['api.themoviedb.org/3/list/120174*' => Http::response(['success' => false, 'status_code' => 34, 'status_message' => 'The resource you requested could not be found.'], 404)]);

    $taskRun = runConnectionImport(tmdbConnection($user), TmdbAccountSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['TMDB list "Kept To Myself": TMDB no longer has it.'])
        ->and($user->lists()->where('title', 'Kept To Myself')->exists())->toBeFalse();
});

test('a session TMDB lost or denied expires the connection, however it is answered', function (int $status, int $code) {
    $connection = tmdbConnection(User::factory()->create());
    Http::fake(['api.themoviedb.org/3/*' => Http::response(['success' => false, 'status_code' => $code], $status)]);

    runConnectionImport($connection, TmdbAccountSource::CODE);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Expired);
})->with([
    'session not found' => [404, 37],
    'session denied' => [401, 17],
]);

test('a connection missing its account or session expires before asking anything', function (array $credentials, array $settings) {
    $connection = activeConnection(User::factory()->create(), TmdbService::CODE, $credentials, ['settings' => $settings]);
    Http::fake();

    runConnectionImport($connection, TmdbAccountSource::CODE);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(Http::recorded())->toHaveCount(0);
})->with([
    'no account' => [['session_id' => 'session-of-alex'], []],
    'no session' => [[], ['account_id' => '548']],
]);

test('a 429 leaves a fresh run for as long as TMDB asks', function () {
    $connection = tmdbConnection(User::factory()->create());
    Http::fake(['api.themoviedb.org/3/*' => Http::response(['success' => false, 'status_code' => 25], 429, ['Retry-After' => '10'])]);

    expect(postponedBy($connection->user, TmdbAccountSource::CODE, ImportInput::connection($connection->id)))->toBe(10)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});
