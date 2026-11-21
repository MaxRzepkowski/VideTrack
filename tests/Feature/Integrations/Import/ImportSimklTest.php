<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Simkl\SimklUnavailable;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * Simkl is exercised on the shapes of its own documentation (fixtures in
 * tests/Fixtures/transfer/simkl, see the README there) — never live.
 */

beforeEach(function () {
    // Before any registry is resolved: the source and the service register
    // only on an instance with a client id.
    config(['tracker.simkl.client_id' => 'simkl-client', 'tracker.version' => '1.2.3']);
    Sleep::fake();
    // Nothing here may reach the real Simkl: an unmatched URL fails the test
    // instead of going out.
    Http::preventStrayRequests();
});

function simklConnection(User $user, array $credentials = [], array $attributes = []): ServiceConnection
{
    return deviceConnection($user, 'simkl', [
        'access_token' => 'simkl_at_one',
        'refresh_token' => 'simkl_rt_one',
        'expires_at' => now()->addDays(6)->getTimestamp(),
        ...$credentials,
    ], ['account_label' => 'jane_doe', ...$attributes]);
}

/**
 * Game of Thrones (TMDB 1399) with season 1's first two episodes, The
 * Walking Dead (1402), Attack on Titan (1429, reached by TVDB 267440),
 * The Godfather (238), Superman (1061474) and Princess Mononoke (128).
 *
 * @return array<string, MediaItem>
 */
function simklCatalogue(): array
{
    $tree = fn (string $ref): MediaItem => showWithRefs($ref, [1 => ['2011-04-17', '2011-04-24']]);

    seedAnimeIdMap();

    return [
        'got' => $tree('1399'),
        'twd' => $tree('1402'),
        'aot' => $tree('1429'),
        'godfather' => MediaItem::factory()->movie()->create(['provider_ref' => '238']),
        'superman' => MediaItem::factory()->movie()->create(['provider_ref' => '1061474']),
        'mononoke' => MediaItem::factory()->movie()->create(['provider_ref' => '128']),
    ];
}

/**
 * @return array<string, mixed>
 */
function simklTmdbFakes(): array
{
    return [
        '*/find/267440*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => 1429]]]),
        '*/find/tt0119698*' => Http::response(['movie_results' => [['id' => 128]], 'tv_results' => []]),
        '*/find/tt1520211*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => 1402]]]),
        'api.themoviedb.org/*' => Http::response(['status_message' => 'Not found'], 404),
    ];
}

/**
 * The whole library, answered per type, with the activities timestamp.
 *
 * @return array<string, mixed>
 */
function simklLibraryFakes(): array
{
    return [
        'api.simkl.com/sync/activities*' => Http::response(jsonFixture('transfer/simkl/activities')),
        'api.simkl.com/sync/all-items/shows*' => Http::response(jsonFixture('transfer/simkl/all-items-shows')),
        'api.simkl.com/sync/all-items/movies*' => Http::response(jsonFixture('transfer/simkl/all-items-movies')),
        'api.simkl.com/sync/all-items/anime*' => Http::response(jsonFixture('transfer/simkl/all-items-anime')),
    ];
}

test('without a client id Simkl is not offered at all', function () {
    config(['tracker.simkl.client_id' => '']);
    app()->forgetInstance(ImportSourceRegistry::class);
    app()->forgetInstance(ConnectionRegistry::class);

    expect(app(ImportSourceRegistry::class)->has('simkl'))->toBeFalse()
        ->and(app(ConnectionRegistry::class)->has('simkl'))->toBeFalse();
});

test('the import page offers Simkl with a code to type at simkl.com', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/import')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === 'simkl'
                && $source['inputKind'] === 'connection'
                && $source['connect']['method'] === 'device'
                && $source['connect']['name'] === 'Simkl')));
});

test('connecting asks Simkl for a read-only device code, without a secret', function () {
    Http::fake(['api.simkl.com/oauth2/device' => Http::response([
        'device_code' => 'dev-123',
        'user_code' => 'BDWP-HQPK',
        'verification_uri' => 'https://simkl.com/pin',
        'verification_uri_complete' => 'https://simkl.com/pin?user_code=BDWP-HQPK',
        'expires_in' => 900,
        'interval' => 5,
    ])]);

    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/simkl/device')
        ->assertOk()
        ->assertJsonPath('userCode', 'BDWP-HQPK')
        ->assertJsonPath('verificationUriComplete', 'https://simkl.com/pin?user_code=BDWP-HQPK')
        ->assertDontSee('dev-123');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.simkl.com/oauth2/device'
        && $request->method() === 'POST'
        && $request['client_id'] === 'simkl-client'
        && $request['scope'] === 'media:read'
        && ! isset($request['client_secret'])
        && $request->header('User-Agent') === [config('tracker.http.user_agent')]);
});

test('an approved code is kept when the account name cannot be read', function () {
    Http::fake([
        'api.simkl.com/oauth2/device' => Http::response(['device_code' => 'dev-123', 'user_code' => 'BDWP-HQPK', 'verification_uri' => 'https://simkl.com/pin', 'expires_in' => 900, 'interval' => 5]),
        'api.simkl.com/oauth2/token' => Http::response(['access_token' => 'simkl_at_new', 'refresh_token' => 'simkl_rt_new', 'token_type' => 'bearer', 'expires_in' => 604800, 'scope' => 'media:read']),
        'api.simkl.com/users/settings*' => Http::failedConnection(),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/settings/connections/simkl/device');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/simkl/device/poll')->assertJsonPath('status', 'connected');

    expect($user->serviceConnections()->sole()->credential('access_token'))->toBe('simkl_at_new');
});

test('an approved code becomes a connection named after the Simkl account', function () {
    $polls = [
        Http::response(['error' => 'authorization_pending'], 400),
        Http::response(['error' => 'slow_down'], 400),
        Http::response(['access_token' => 'simkl_at_new', 'refresh_token' => 'simkl_rt_new', 'token_type' => 'bearer', 'expires_in' => 604800, 'scope' => 'media:read']),
    ];

    Http::fake([
        'api.simkl.com/oauth2/device' => Http::response(['device_code' => 'dev-123', 'user_code' => 'BDWP-HQPK', 'verification_uri' => 'https://simkl.com/pin', 'expires_in' => 900, 'interval' => 5]),
        'api.simkl.com/oauth2/token' => function () use (&$polls) {
            return array_shift($polls);
        },
        'api.simkl.com/users/settings*' => Http::response(jsonFixture('transfer/simkl/users-settings')),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/settings/connections/simkl/device');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/simkl/device/poll')->assertJsonPath('status', 'pending');

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/simkl/device/poll')->assertJsonPath('status', 'pending');

    // slow_down: ten seconds now, not five.
    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/simkl/device/poll')->assertJsonPath('status', 'pending');
    expect(requestsTo('/oauth2/token'))->toBe(2);

    $this->travel(5)->seconds();
    $this->postJson('/settings/connections/simkl/device/poll')
        ->assertJsonPath('status', 'connected')
        ->assertJsonPath('connection.account', 'jane_doe');

    $connection = $user->serviceConnections()->sole();

    expect($connection->credential('access_token'))->toBe('simkl_at_new')
        ->and($connection->credential('refresh_token'))->toBe('simkl_rt_new')
        ->and((int) $connection->credential('expires_at'))->toBeGreaterThan(now()->addDays(6)->getTimestamp());

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/token')
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:device_code'
        && $request['device_code'] === 'dev-123');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users/settings')
        && $request->method() === 'GET'
        && $request['client_id'] === 'simkl-client'
        && $request['app-name'] === 'videtrack'
        && $request['app-version'] === '1.2.3'
        && $request->header('Authorization') === ['Bearer simkl_at_new']);
});

test('a V1 client id is turned down once, with the reason', function () {
    Http::fake(['api.simkl.com/oauth2/device' => Http::response(['error' => 'invalid_client', 'error_description' => 'This client_id is not enabled for OAuth 2.0'], 401)]);

    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/simkl/device')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Simkl did not accept this instance. Its client id must belong to an AUTH V2 app.')
        ->assertJsonValidationErrors(['connection' => 'Simkl did not accept this instance.']);

    expect(requestsTo('/oauth2/device'))->toBe(1);
});

test('the first run reads the whole library one type after another', function () {
    Http::fake([...simklLibraryFakes(), ...simklTmdbFakes()]);
    $items = simklCatalogue();
    $user = User::factory()->create();
    $connection = simklConnection($user);

    $taskRun = runConnectionImport($connection, 'simkl');

    expect($taskRun->status)->toBe(TaskRunStatus::Success);

    // Sequential, in the documented order, each with the full episode flags.
    $paths = Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.simkl.com'))
        ->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))
        ->values()
        ->all();

    expect($paths)->toBe(['/sync/activities', '/sync/all-items/shows', '/sync/all-items/movies', '/sync/all-items/anime']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sync/all-items/shows')
        && $request['extended'] === 'full'
        && $request['episode_watched_at'] === 'yes'
        && $request['include_all_episodes'] === 'yes'
        && ! isset($request['date_from'])
        && $request['client_id'] === 'simkl-client');

    // Game of Thrones: two episodes, each at its own time; the rating on the show.
    $gotPlays = $user->plays()
        ->whereHas('mediaItem', fn ($q) => $q->where('provider_ref', 'like', '1399:1:%'))
        ->orderBy('watched_at')
        ->pluck('watched_at')
        ->map->toIso8601String()
        ->all();

    expect($gotPlays)->toBe(['2026-05-14T20:00:00+00:00', '2026-05-15T00:13:18+00:00'])
        ->and($user->ratings()->where('media_item_id', $items['got']->id)->value('rating'))->toBe(9)
        ->and($user->mediaStates()->where('media_item_id', $items['got']->id)->exists())->toBeFalse();

    // The Walking Dead has no TMDB id at Simkl: placed by IMDb, on the watchlist.
    expect($user->lists()->where('system_key', 'watchlist')->firstOrFail()->items()->where('media_item_id', $items['twd']->id)->exists())->toBeTrue();

    // The Godfather: a play on the date Simkl holds, and the 10.
    $godfather = $user->plays()->where('media_item_id', $items['godfather']->id)->sole();
    expect($godfather->watched_at->toDateString())->toBe('1994-09-01')
        ->and($godfather->outcome)->toBe(PlayOutcome::Completed)
        ->and($user->ratings()->where('media_item_id', $items['godfather']->id)->value('rating'))->toBe(10);

    // Attack on Titan: through the anime map to TVDB 267440, episodes by Simkl's TVDB numbering.
    $aotPlays = $user->plays()->whereHas('mediaItem', fn ($q) => $q->where('provider_ref', 'like', '1429:1:%'))->count();
    expect($aotPlays)->toBe(2)
        ->and($user->ratings()->where('media_item_id', $items['aot']->id)->value('rating'))->toBe(8)
        ->and($user->plays()->where('media_item_id', $items['mononoke']->id)->sole()->watched_at->toDateString())->toBe('2026-03-02');

    expect($taskRun->errors)->toContain(
        'No Ids Show (2020): no TMDB, IMDb or TVDB id on Simkl.',
        'Unknown Anime (2024) (MAL 99999): not in the anime id map, so it has no TVDB or IMDb id to go by.',
    );

    expect($connection->refresh()->state)->toBe(['activities_all' => '2026-05-15T00:20:00Z'])
        ->and($connection->last_imported_at)->not->toBeNull();
});

test('a later run stops at the activities when nothing moved', function () {
    Http::fake([...simklLibraryFakes(), ...simklTmdbFakes()]);
    $user = User::factory()->create();
    $connection = simklConnection($user, attributes: ['state' => ['activities_all' => '2026-05-15T00:20:00Z']]);

    $taskRun = runConnectionImport($connection, 'simkl');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/sync/activities'))->toBe(1)
        ->and(requestsTo('/sync/all-items'))->toBe(0)
        ->and($connection->refresh()->state)->toBe(['activities_all' => '2026-05-15T00:20:00Z']);
});

test('a later run reads only what changed since the saved timestamp', function () {
    Http::fake([
        'api.simkl.com/sync/activities*' => Http::response(jsonFixture('transfer/simkl/activities')),
        'api.simkl.com/sync/all-items*' => Http::response(jsonFixture('transfer/simkl/all-items-movies')),
        ...simklTmdbFakes(),
    ]);
    $items = simklCatalogue();
    $user = User::factory()->create();
    $connection = simklConnection($user, attributes: ['state' => ['activities_all' => '2026-05-01T00:00:00Z']]);

    runConnectionImport($connection, 'simkl');

    expect(requestsTo('/sync/all-items'))->toBe(1);

    Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/sync/all-items'
        && $request['date_from'] === '2026-05-01T00:00:00Z'
        && $request['extended'] === 'full');

    expect($user->plays()->where('media_item_id', $items['godfather']->id)->exists())->toBeTrue()
        ->and($connection->refresh()->state)->toBe(['activities_all' => '2026-05-15T00:20:00Z']);
});

test('a token refreshed meanwhile by another worker is picked up, not refreshed again', function () {
    $user = User::factory()->create();
    $connection = simklConnection($user);

    Http::fake([
        'api.simkl.com/sync/activities*' => function (Request $request) use ($connection) {
            if ($request->header('Authorization') === ['Bearer simkl_at_one']) {
                // Someone else refreshed the grant: the stored token moved on.
                ServiceConnection::query()->whereKey($connection->id)->first()
                    ->update(['credentials' => [...$connection->credentials, 'access_token' => 'simkl_at_two']]);

                return Http::response(['error' => 'invalid_token'], 401);
            }

            return Http::response(['all' => '2026-05-15T00:20:00Z']);
        },
        'api.simkl.com/*' => Http::response(jsonFixture('transfer/simkl/all-items-movies')),
        ...simklTmdbFakes(),
    ]);

    $taskRun = runConnectionImport($connection, 'simkl');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/oauth2/token'))->toBe(0);
});

test('an expired access token is refreshed once and the call repeated', function () {
    $user = User::factory()->create();
    $connection = simklConnection($user);

    Http::fake([
        'api.simkl.com/oauth2/token' => Http::response(['access_token' => 'simkl_at_two', 'refresh_token' => 'simkl_rt_one', 'expires_in' => 604800]),
        'api.simkl.com/sync/activities*' => fn (Request $request) => $request->header('Authorization') === ['Bearer simkl_at_two']
            ? Http::response(['all' => '2026-05-15T00:20:00Z'])
            : Http::response(['error' => 'invalid_token'], 401),
        'api.simkl.com/*' => Http::response([]),
    ]);

    $taskRun = runConnectionImport($connection, 'simkl');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/oauth2/token'))->toBe(1)
        ->and($connection->refresh()->credential('access_token'))->toBe('simkl_at_two');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'simkl_rt_one');
});

test('a token close to expiry is refreshed before it is used', function () {
    $user = User::factory()->create();
    $connection = simklConnection($user, ['expires_at' => now()->addHours(3)->getTimestamp()]);

    Http::fake([
        'api.simkl.com/oauth2/token' => Http::response(['access_token' => 'simkl_at_two', 'refresh_token' => 'simkl_rt_one', 'expires_in' => 604800]),
        'api.simkl.com/*' => Http::response(['all' => '2026-05-15T00:20:00Z']),
    ]);

    runConnectionImport($connection, 'simkl');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sync/activities')
        && $request->header('Authorization') === ['Bearer simkl_at_two']);
    Http::assertNotSent(fn (Request $request) => $request->header('Authorization') === ['Bearer simkl_at_one']);
});

test('a grant Simkl no longer honours expires the connection and stops asking', function () {
    $user = User::factory()->create();
    $connection = simklConnection($user);

    Http::fake([
        'api.simkl.com/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        'api.simkl.com/*' => Http::response(['error' => 'invalid_token'], 401),
    ]);

    $run = runConnectionImport($connection, 'simkl');

    expect($run->status)->toBe(TaskRunStatus::Failure)
        ->and($run->summary)->toContain('Simkl stopped accepting this connection. Connect your account again.');

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(requestsTo('/oauth2/token'))->toBe(1)
        ->and(requestsTo('/sync/activities'))->toBe(1);

    // The next run does not even start: the connection has to be made again.
    $this->actingAs($user)
        ->post('/settings/import', ['source' => 'simkl'])
        ->assertSessionHasErrors(['source' => 'Connect your account first.']);
});

test('a 429 is waited out', function () {
    $user = User::factory()->create();
    $connection = simklConnection($user);
    $answers = [Http::response([], 429), Http::response(['all' => '2026-05-15T00:20:00Z'])];

    Http::fake([
        'api.simkl.com/sync/activities*' => function () use (&$answers) {
            return array_shift($answers);
        },
        'api.simkl.com/*' => Http::response([]),
    ]);

    expect(runConnectionImport($connection, 'simkl')->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/sync/activities'))->toBe(2);

    Sleep::assertSleptTimes(1);
});

test('an answered 4xx other than 401 is final: the run ends with it, and the job is not retried', function () {
    Http::fake(['api.simkl.com/*' => Http::response(['error' => 'not_found'], 404)]);
    $second = simklConnection(User::factory()->create());
    $taskRun = runConnectionImport($second, 'simkl');

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('Simkl answered 404 to /sync/activities.')
        ->and($second->refresh()->last_error)->toBe('Simkl answered 404 to /sync/activities.')
        ->and(requestsTo('/sync/activities'))->toBe(1);
    Sleep::assertNeverSlept();
});

test('disconnecting revokes the grant at Simkl', function () {
    Http::fake(['api.simkl.com/oauth2/revoke' => Http::response([])]);
    $user = User::factory()->create();
    $connection = simklConnection($user);

    $this->actingAs($user)->delete("/settings/connections/{$connection->id}")->assertRedirect();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/revoke')
        && $request['token'] === 'simkl_rt_one'
        && $request['client_id'] === 'simkl-client');

    expect(ServiceConnection::query()->count())->toBe(0);
});

test('a status on hold or dropped lands on the show, not on its episodes', function () {
    Http::fake([
        'api.simkl.com/sync/activities*' => Http::response(['all' => '2026-05-15T00:20:00Z']),
        'api.simkl.com/sync/all-items/shows*' => Http::response(['shows' => [
            [
                'status' => 'dropped',
                'last_watched_at' => '2026-05-15T00:13:18Z',
                'show' => ['title' => 'Game of Thrones', 'year' => 2011, 'ids' => ['tmdb' => '1399']],
                'seasons' => [['number' => 1, 'episodes' => [['number' => 1, 'watched_at' => '2026-05-14T20:00:00Z']]]],
            ],
        ]]),
        'api.simkl.com/sync/all-items/*' => Http::response([]),
        ...simklTmdbFakes(),
    ]);
    $items = simklCatalogue();
    $user = User::factory()->create();

    runConnectionImport(simklConnection($user), 'simkl');

    expect($user->plays()->where('media_item_id', $items['got']->id)->sole()->outcome)->toBe(PlayOutcome::Dropped)
        ->and($user->plays()->whereHas('mediaItem', fn ($q) => $q->where('type', 'episode'))->count())->toBe(1)
        ->and($user->mediaStates()->where('media_item_id', $items['got']->id)->value('status'))->not->toBe(MediaUserStatus::InProgress);
});

test('activities without their moment stop the run and keep the position, rather than reading everything', function () {
    Http::fake([
        ...simklLibraryFakes(),
        // Overrides the library's activities: the same key, later in the array.
        'api.simkl.com/sync/activities*' => Http::response(['tv_shows' => []]),
        ...simklTmdbFakes(),
    ]);
    $user = User::factory()->create();
    $connection = simklConnection($user, attributes: ['state' => ['activities_all' => '2026-05-15T00:20:00Z']]);

    // Unavailable, so the queue tries the run again later.
    expect(fn () => runConnectionImport($connection, 'simkl'))->toThrow(SimklUnavailable::class)
        ->and(requestsTo('/sync/all-items'))->toBe(0)
        ->and($connection->refresh()->state)->toBe(['activities_all' => '2026-05-15T00:20:00Z']);
});
