<?php

use App\Enums\TaskRunStatus;
use App\Integrations\Anime\AnimeListUnavailable;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\OwnServerClient;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Nuvio\NuvioClient;
use App\Integrations\Nuvio\NuvioService;
use App\Integrations\Nuvio\NuvioSource;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * Nuvio is exercised on the shapes its public API documentation gives
 * (nuvio.tv/docs/nuvio-public-api.md, v1.2) — never live.
 */

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

const NUVIO = NuvioClient::CLOUD_URL;

/** A signed-in session, as Supabase answers it. */
function nuvioSession(string $suffix = 'one'): array
{
    return ['access_token' => "access-of-alex-{$suffix}", 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => "refresh-of-alex-{$suffix}", 'user' => ['id' => 'uuid', 'email' => 'alex@example.com']];
}

/** @param  array<string, mixed>  $attributes */
function nuvioConnection(User $user, array $settings = ['profile' => 1], array $attributes = []): ServiceConnection
{
    return activeConnection($user, NuvioService::CODE, [
        'url' => NUVIO,
        'access_token' => 'access-of-alex-one',
        'refresh_token' => 'refresh-of-alex-one',
        'expires_at' => now()->addHour()->getTimestamp(),
    ], ['settings' => $settings, ...$attributes]);
}

/** One entry of the history or its change feed. */
function nuvioItem(string $contentId, string $type, ?int $season = null, ?int $episode = null, string $title = 'Title', string $at = '2026-10-01 20:00:00', int $event = 1, string $operation = 'upsert'): array
{
    return [
        'event_id' => $event,
        'operation' => $operation,
        'content_id' => $contentId,
        'content_type' => $type,
        'title' => $title,
        'season' => $season,
        'episode' => $episode,
        'watched_at' => (int) (strtotime($at.' UTC') * 1000),
    ];
}

/**
 * The account's RPCs: $changes answered by the event they start after.
 *
 * @param  array<int, list<array<string, mixed>>>  $changes
 * @param  list<list<array<string, mixed>>>  $history
 */
function nuvioFakes(array $changes = [], array $history = [], int $cursor = 40): array
{
    return [
        'api.nuvio.tv/auth/v1/token?grant_type=password' => Http::response(nuvioSession()),
        'api.nuvio.tv/auth/v1/logout*' => Http::response(null, 204),
        'api.nuvio.tv/rest/v1/rpc/sync_pull_profiles' => Http::response([
            ['id' => 'uuid-1', 'profile_index' => 1, 'name' => 'Main'],
            ['id' => 'uuid-2', 'profile_index' => 2, 'name' => 'Kids'],
        ]),
        'api.nuvio.tv/rest/v1/rpc/sync_get_watched_items_delta_cursor' => Http::response((string) $cursor, 200, ['Content-Type' => 'application/json']),
        'api.nuvio.tv/rest/v1/rpc/sync_pull_watched_items' => fn (Request $request) => Http::response($history[$request['p_page'] - 1] ?? []),
        'api.nuvio.tv/rest/v1/rpc/sync_pull_watched_items_delta' => fn (Request $request) => Http::response($changes[$request['p_since_event_id']] ?? []),
        ...providerDictionaryFakes(),
    ];
}

/** @return array{matrix: MediaItem, got: MediaItem} */
function nuvioCatalogue(): array
{
    return [
        'matrix' => MediaItem::factory()->movie()->create(['provider_ref' => '603']),
        'got' => showWithRefs('1399', [1 => ['2011-04-17', '2011-04-24']]),
    ];
}

function rpcCalls(string $function): int
{
    return Http::recorded(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), "/rpc/{$function}"))->count();
}

test('the integrations page offers Nuvio: an account, an optional own server and the history left out unless asked for', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.integrations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.accounts', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === NuvioSource::CODE
                && $source['connect']['method'] === 'form'
                && $source['connect']['autoImportMinutes'] === NuvioSource::AUTO_IMPORT_MINUTES
                && array_column($source['connect']['fields'], 'key') === ['email', 'password', 'url', 'history']
                && $source['connect']['fields'][2]['optional'] === true
                && $source['connect']['fields'][3]['type'] === 'checkbox')));
});

test('signing in lists the profiles and ends the session it made to look', function () {
    Http::fake(nuvioFakes());

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', NuvioService::CODE), ['values' => ['email' => 'alex@example.com', 'password' => 'password-of-alex']])
        ->assertOk()
        ->assertJsonPath('choices', [['id' => '1', 'label' => 'Main'], ['id' => '2', 'label' => 'Kids']]);

    Http::assertSent(fn (Request $request) => $request->url() === NUVIO.'/auth/v1/token?grant_type=password'
        && $request->data() === ['email' => 'alex@example.com', 'password' => 'password-of-alex']
        && $request->header('apikey') === [NuvioClient::CLOUD_KEY]);
    Http::assertSent(fn (Request $request) => $request->url() === NUVIO.'/auth/v1/logout?scope=local'
        && $request->hasHeader('Authorization', 'Bearer access-of-alex-one'));
});

test('a wrong e-mail or password is asked about once', function () {
    Http::fake(['api.nuvio.tv/*' => Http::response(['code' => 400, 'error_code' => 'invalid_credentials', 'msg' => 'Invalid login credentials'], 400)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', NuvioService::CODE), ['values' => ['email' => 'alex@example.com', 'password' => 'wrong']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => 'Nuvio did not accept this e-mail and password.']);

    expect(requestsTo('/auth/v1/token'))->toBe(1);
});

test('connecting keeps a session of its own, never the password, and starts from now unless the history is asked for', function (string $history, ?int $start) {
    Http::fake(nuvioFakes(cursor: 40));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', NuvioService::CODE), [
            'values' => ['email' => 'alex@example.com', 'password' => 'password-of-alex', 'history' => $history],
            'choice' => '2',
        ])
        ->assertOk()
        ->assertJsonPath('connection.account', 'alex@example.com – Kids');

    $connection = $user->serviceConnections()->sole();

    expect($connection->credentials)->toMatchArray(['url' => NUVIO, 'access_token' => 'access-of-alex-one', 'refresh_token' => 'refresh-of-alex-one'])
        ->and($connection->credentials)->not->toHaveKey('password')
        ->and($connection->credential('key'))->toBeNull()
        ->and($connection->setting('profile'))->toBe('2')
        ->and($connection->setting('start_event_id'))->toBe($start === null ? null : (string) $start);

    // The feed's position is asked of the profile picked.
    if ($start !== null) {
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sync_get_watched_items_delta_cursor') && $request['p_profile_id'] === 2);
    }
})->with([
    'from now' => ['', 40],
    'the whole history' => ['1', null],
]);

test('a run from now reads the changes since connecting and keeps where it stopped', function () {
    ['matrix' => $matrix, 'got' => $got] = nuvioCatalogue();
    Http::fake(nuvioFakes(changes: [40 => [
        nuvioItem('tmdb:603', 'movie', title: 'The Matrix', event: 41),
        nuvioItem('tmdb:1399', 'series', 1, 1, 'Game of Thrones', event: 42),
        // A show's summary line, written beside its episodes.
        nuvioItem('tmdb:1399', 'series', title: 'Game of Thrones', event: 43),
        // Removed in Nuvio: stays watched here.
        nuvioItem('tmdb:1399', 'series', 1, 2, 'Game of Thrones', event: 44, operation: 'delete'),
        nuvioItem('mal:5114', 'series', 1, 1, 'Fullmetal Alchemist', event: 45),
    ]]));
    $user = User::factory()->create();
    $connection = nuvioConnection($user, ['profile' => 1, 'start_event_id' => 40]);

    $run = runConnectionImport($connection, NuvioSource::CODE);

    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($user->plays()->where('media_item_id', $matrix->id)->sole()->watched_at->toIso8601String())->toBe('2026-10-01T20:00:00+00:00')
        ->and($user->plays()->where('media_item_id', episodeOf($got, 1, 1)->id)->count())->toBe(1)
        ->and($user->plays()->where('media_item_id', episodeOf($got, 1, 2)->id)->exists())->toBeFalse()
        ->and($user->plays()->count())->toBe(2)
        ->and($run->errors)->toContain('Fullmetal Alchemist S01E01: Nuvio names it mal:5114, which this instance cannot place.')
        ->and($connection->refresh()->state)->toBe(['event_id' => 45])
        ->and(rpcCalls('sync_pull_watched_items'))->toBe(0);
});

test('the next run asks only for what changed after the last event, and an empty answer is one request', function () {
    Http::fake(nuvioFakes());
    $connection = nuvioConnection(User::factory()->create(), attributes: ['state' => ['event_id' => 45]]);

    runConnectionImport($connection, NuvioSource::CODE);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sync_pull_watched_items_delta')
        && $request->data() === ['p_profile_id' => 1, 'p_since_event_id' => 45, 'p_limit' => NuvioClient::CHANGES_PAGE]);

    expect(rpcCalls('sync_pull_watched_items_delta'))->toBe(1)
        ->and($connection->refresh()->state)->toBe(['event_id' => 45]);
});

test('a full page of changes asks for the next one', function () {
    $page = array_map(fn (int $event) => nuvioItem('tt0000001', 'series', event: $event), range(1, NuvioClient::CHANGES_PAGE));
    Http::fake(nuvioFakes(changes: [0 => $page, NuvioClient::CHANGES_PAGE => [nuvioItem('tt0000001', 'series', event: NuvioClient::CHANGES_PAGE + 1)]]));
    $connection = nuvioConnection(User::factory()->create(), ['profile' => 1, 'start_event_id' => 0]);

    runConnectionImport($connection, NuvioSource::CODE);

    expect(rpcCalls('sync_pull_watched_items_delta'))->toBe(2)
        ->and($connection->refresh()->state)->toBe(['event_id' => NuvioClient::CHANGES_PAGE + 1]);
});

test('the whole history comes in page by page, then what changed while it was read', function () {
    ['matrix' => $matrix, 'got' => $got] = nuvioCatalogue();
    $fullPage = array_map(fn (int $n) => nuvioItem('tmdb:603', 'movie', at: '2026-01-01 20:00:00'), range(1, NuvioClient::HISTORY_PAGE));
    Http::fake(nuvioFakes(
        changes: [40 => [nuvioItem('tmdb:1399', 'series', 1, 2, event: 41)]],
        history: [$fullPage, [nuvioItem('tmdb:1399', 'series', 1, 1)]],
        cursor: 40,
    ));
    $user = User::factory()->create();
    $connection = nuvioConnection($user);

    runConnectionImport($connection, NuvioSource::CODE);

    expect(rpcCalls('sync_pull_watched_items'))->toBe(2)
        ->and($user->plays()->where('media_item_id', $matrix->id)->count())->toBe(1)
        ->and($user->plays()->where('media_item_id', episodeOf($got, 1, 1)->id)->count())->toBe(1)
        ->and($user->plays()->where('media_item_id', episodeOf($got, 1, 2)->id)->count())->toBe(1)
        ->and($connection->refresh()->state)->toBe(['event_id' => 41]);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sync_pull_watched_items')
        && $request->data() === ['p_profile_id' => 1, 'p_page' => 1, 'p_page_size' => NuvioClient::HISTORY_PAGE]);
});

test('an anime add-on\'s title is placed through Kitsu and the anime id map, Kitsu asked once a title', function () {
    seedAnimeIdMap();
    ['show' => $show] = animeCatalogue();
    Http::fake([
        ...nuvioFakes(changes: [0 => [
            nuvioItem('kitsu:7442', 'series', 1, 1, 'Attack on Titan', event: 1),
            nuvioItem('kitsu:7442', 'series', 1, 2, 'Attack on Titan', event: 2),
        ]]),
        config()->string('tracker.imports.kitsu_url').'/anime/7442*' => Http::response([
            'data' => ['type' => 'anime', 'id' => '7442', 'relationships' => ['mappings' => ['data' => [['type' => 'mappings', 'id' => '9']]]]],
            'included' => [['type' => 'mappings', 'id' => '9', 'attributes' => ['externalSite' => 'myanimelist/anime', 'externalId' => '16498']]],
        ]),
        ...tmdbAnimeFakes(),
    ]);
    $user = User::factory()->create();

    runConnectionImport(nuvioConnection($user, ['profile' => 1, 'start_event_id' => 0]), NuvioSource::CODE);

    expect($user->plays()->whereIn('media_item_id', [episodeOf($show, 1, 1)->id, episodeOf($show, 1, 2)->id])->count())->toBe(2)
        ->and(requestsTo('/anime/7442'))->toBe(1);
});

test('a session in its last minutes is refreshed under the key before the run reads, its rotated token kept', function () {
    Http::fake([
        'api.nuvio.tv/auth/v1/token?grant_type=refresh_token' => Http::response(nuvioSession('two')),
        ...nuvioFakes(),
    ]);
    $connection = nuvioConnection(User::factory()->create(), attributes: ['credentials' => [
        'url' => NUVIO,
        'access_token' => 'access-of-alex-one',
        'refresh_token' => 'refresh-of-alex-one',
        'expires_at' => now()->addSeconds(NuvioClient::REFRESH_MARGIN_SECONDS - 60)->getTimestamp(),
    ], 'state' => ['event_id' => 1]]);

    runConnectionImport($connection, NuvioSource::CODE);

    Http::assertSent(fn (Request $request) => $request->url() === NUVIO.'/auth/v1/token?grant_type=refresh_token'
        && $request->data() === ['refresh_token' => 'refresh-of-alex-one']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rpc/') && $request->hasHeader('Authorization', 'Bearer access-of-alex-two'));

    expect($connection->refresh()->credential('refresh_token'))->toBe('refresh-of-alex-two');
});

test('a session Nuvio no longer accepts expires the connection', function () {
    Http::fake([
        'api.nuvio.tv/rest/*' => Http::response(['message' => 'JWT expired'], 401),
        'api.nuvio.tv/auth/v1/token*' => Http::response(['error_code' => 'refresh_token_already_used'], 400),
    ]);
    $connection = nuvioConnection(User::factory()->create(), attributes: ['state' => ['event_id' => 1]]);

    $run = runConnectionImport($connection, NuvioSource::CODE);

    expect($run->status)->toBe(TaskRunStatus::Failure)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(requestsTo('/auth/v1/token'))->toBe(1);
});

test('a busy Nuvio leaves a fresh run and the position where it was', function () {
    Http::fake(['api.nuvio.tv/rest/*' => Http::response([], 429)]);
    $user = User::factory()->create();
    $connection = nuvioConnection($user, attributes: ['state' => ['event_id' => 7]]);

    expect(postponedBy($user, NuvioSource::CODE, ImportInput::connection($connection->id)))->toBe(OwnServerClient::DEFAULT_WAIT_SECONDS)
        ->and($connection->refresh()->state)->toBe(['event_id' => 7]);
});

test('disconnecting ends this session only, so the apps stay signed in', function () {
    Http::fake(['api.nuvio.tv/auth/v1/logout*' => Http::response(null, 204)]);
    $user = User::factory()->create();
    $connection = nuvioConnection($user);

    $this->actingAs($user)->delete(route('settings.connections.destroy', $connection))->assertRedirect();

    Http::assertSent(fn (Request $request) => $request->url() === NUVIO.'/auth/v1/logout?scope=local'
        && $request->hasHeader('Authorization', 'Bearer access-of-alex-one'));

    expect(ServiceConnection::query()->count())->toBe(0);
});

test('an own Nuvio server is found by the key it announces, and kept with it', function () {
    Http::fake([
        'nuvio.lan:8080/.well-known/nuvio' => Http::response(['service' => 'nuvio', 'self_hosted' => true, 'publishable_key' => 'publishable-key-of-the-lan', 'backend_url' => 'http://localhost:54321']),
        'nuvio.lan:8080/auth/v1/token*' => Http::response(nuvioSession()),
        'nuvio.lan:8080/auth/v1/logout*' => Http::response(null, 204),
        'nuvio.lan:8080/rest/v1/rpc/sync_pull_profiles' => Http::response([['profile_index' => 1, 'name' => 'Main']]),
        'nuvio.lan:8080/rest/v1/rpc/sync_get_watched_items_delta_cursor' => Http::response('3', 200, ['Content-Type' => 'application/json']),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', NuvioService::CODE), [
            'values' => ['email' => 'alex@example.com', 'password' => 'password-of-alex', 'url' => 'http://nuvio.lan:8080/'],
            'choice' => '1',
        ])
        ->assertOk();

    $connection = $user->serviceConnections()->sole();

    // The document's own address reads localhost on a one-machine server: never followed.
    expect($connection->credential('url'))->toBe('http://nuvio.lan:8080')
        ->and($connection->credential('key'))->toBe('publishable-key-of-the-lan');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'nuvio.lan:8080/auth/') && $request->header('apikey') === ['publishable-key-of-the-lan']);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'localhost'));
});

test('an address that is not a Nuvio server is turned down', function (mixed $answer, string $message) {
    Http::fake(['nuvio.lan:8080/*' => Http::response($answer)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', NuvioService::CODE), ['values' => ['email' => 'alex@example.com', 'password' => 'password-of-alex', 'url' => 'http://nuvio.lan:8080']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message]);

    expect(requestsTo('/auth/v1/token'))->toBe(0);
})->with([
    'another service' => [['service' => 'jellyfin'], 'There is no Nuvio server at this address.'],
    'a web page' => ['<html>Router</html>', 'Nuvio could not be read at this address.'],
]);

test('seeking past the end again and again is one viewing', function () {
    ['matrix' => $matrix] = nuvioCatalogue();
    // Nuvio records the viewing anew each time playback passes 90%.
    Http::fake(nuvioFakes(changes: [0 => [
        nuvioItem('tmdb:603', 'movie', at: '2026-11-28 15:56:12', event: 1),
        nuvioItem('tmdb:603', 'movie', at: '2026-11-28 15:56:22', event: 2),
        nuvioItem('tmdb:603', 'movie', at: '2026-11-28 15:56:27', event: 3),
    ]]));
    $user = User::factory()->create();

    runConnectionImport(nuvioConnection($user, ['profile' => 1, 'start_event_id' => 0]), NuvioSource::CODE);

    expect($user->plays()->where('media_item_id', $matrix->id)->count())->toBe(1);
});

test('a film and an episode named by their IMDb ids are found through TMDB', function () {
    ['matrix' => $matrix, 'got' => $got] = nuvioCatalogue();
    Http::fake([
        ...nuvioFakes(changes: [0 => [
            nuvioItem('tt0133093', 'movie', title: 'The Matrix', event: 1),
            nuvioItem('tt0944947', 'series', 1, 2, 'Game of Thrones', event: 2),
        ]]),
        '*/find/tt0133093*' => Http::response(['movie_results' => [['id' => 603]], 'tv_results' => []]),
        '*/find/tt0944947*' => Http::response(['movie_results' => [], 'tv_results' => [['id' => 1399]]]),
        'api.themoviedb.org/*' => Http::response(['status_message' => 'Not found'], 404),
    ]);
    $user = User::factory()->create();

    runConnectionImport(nuvioConnection($user, ['profile' => 1, 'start_event_id' => 0]), NuvioSource::CODE);

    expect($user->plays()->where('media_item_id', $matrix->id)->count())->toBe(1)
        ->and($user->plays()->where('media_item_id', episodeOf($got, 1, 2)->id)->count())->toBe(1);
});

test('an anime film is placed by its own IMDb id; a Kitsu id with no title is reported', function () {
    seedAnimeIdMap();
    ['film' => $film] = animeCatalogue();
    $kitsu = config()->string('tracker.imports.kitsu_url');
    Http::fake([
        ...nuvioFakes(changes: [0 => [
            nuvioItem('kitsu:12', 'movie', title: 'Princess Mononoke', event: 1),
            nuvioItem('kitsu:999', 'series', 1, 1, 'Gone', event: 2),
        ]]),
        "{$kitsu}/anime/12*" => Http::response([
            'data' => ['type' => 'anime', 'id' => '12', 'relationships' => ['mappings' => ['data' => [['type' => 'mappings', 'id' => '3']]]]],
            'included' => [['type' => 'mappings', 'id' => '3', 'attributes' => ['externalSite' => 'myanimelist/anime', 'externalId' => '164']]],
        ]),
        "{$kitsu}/anime/999*" => Http::response(['errors' => [['status' => '404']]], 404),
        ...tmdbAnimeFakes(),
    ]);
    $user = User::factory()->create();

    $run = runConnectionImport(nuvioConnection($user, ['profile' => 1, 'start_event_id' => 0]), NuvioSource::CODE);

    expect($user->plays()->where('media_item_id', $film->id)->count())->toBe(1)
        ->and($run->errors)->toContain('Gone S01E01: Kitsu holds no title under this id, so it cannot be placed.');
});

test('a cursor Nuvio answers in a shape it does not document stops the connect, rather than reading from the start', function () {
    Http::fake([
        ...nuvioFakes(),
        'api.nuvio.tv/rest/v1/rpc/sync_get_watched_items_delta_cursor' => Http::response(['cursor' => 40]),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', NuvioService::CODE), ['values' => ['email' => 'alex@example.com', 'password' => 'password-of-alex'], 'choice' => '1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => 'Nuvio could not be read at this address.']);

    // Not connected: the session made for it is ended too.
    expect($user->serviceConnections()->exists())->toBeFalse()
        ->and(requestsTo('/auth/v1/logout'))->toBe(1);
});

test('an account without a profile is told so instead of offering nothing to pick', function () {
    Http::fake([
        ...nuvioFakes(),
        'api.nuvio.tv/rest/v1/rpc/sync_pull_profiles' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', NuvioService::CODE), ['values' => ['email' => 'alex@example.com', 'password' => 'password-of-alex']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => 'This Nuvio account has no profile yet. Open Nuvio once, then connect again.']);

    expect(requestsTo('/auth/v1/logout'))->toBe(1);
});

test('a session on an own server is refreshed at the address it was approved at', function () {
    $refresh = [];
    Http::fake(function (Request $request, array $options) use (&$refresh) {
        if (str_contains($request->url(), '/auth/v1/token')) {
            $refresh = $options;

            return Http::response(nuvioSession('two'));
        }

        return Http::response([]);
    });
    $connection = activeConnection(User::factory()->create(), NuvioService::CODE, [
        'url' => 'http://nuvio.lan:8080',
        'key' => 'publishable-key-of-the-lan',
        'access_token' => 'access-of-alex-one',
        'refresh_token' => 'refresh-of-alex-one',
        'expires_at' => now()->addSeconds(NuvioClient::REFRESH_MARGIN_SECONDS - 60)->getTimestamp(),
    ], ['settings' => ['profile' => 1], 'state' => ['event_id' => 1]]);

    runConnectionImport($connection, NuvioSource::CODE);

    expect($refresh['curl'][CURLOPT_RESOLVE])->toBe(['nuvio.lan:8080:192.168.1.24'])
        ->and($refresh['allow_redirects'])->toBeFalse()
        ->and($connection->refresh()->credential('access_token'))->toBe('access-of-alex-two');
});

test('a title Kitsu will not show is reported, and the rest of the feed still comes in', function () {
    seedAnimeIdMap();
    ['matrix' => $matrix] = nuvioCatalogue();
    Http::fake([
        ...nuvioFakes(changes: [0 => [
            nuvioItem('kitsu:13', 'series', 1, 1, 'Hidden', event: 1),
            nuvioItem('tmdb:603', 'movie', title: 'The Matrix', event: 2),
        ]]),
        config()->string('tracker.imports.kitsu_url').'/anime/13*' => Http::response(['errors' => [['status' => '403']]], 403),
    ]);
    $user = User::factory()->create();
    $connection = nuvioConnection($user, ['profile' => 1, 'start_event_id' => 0]);

    $run = runConnectionImport($connection, NuvioSource::CODE);

    expect($run->errors)->toContain('Hidden S01E01: Kitsu would not show this title, so it cannot be placed.')
        ->and($user->plays()->where('media_item_id', $matrix->id)->count())->toBe(1)
        ->and($connection->refresh()->state)->toBe(['event_id' => 2]);
});

test('an anime viewing waits for the anime id map rather than being skipped for good', function () {
    Storage::fake('local');
    Http::fake([
        ...nuvioFakes(changes: [0 => [nuvioItem('kitsu:7442', 'series', 1, 1, 'Attack on Titan', event: 1)]]),
        config()->string('tracker.imports.kitsu_url').'/anime/7442*' => Http::response([
            'data' => ['type' => 'anime', 'id' => '7442', 'relationships' => ['mappings' => ['data' => [['type' => 'mappings', 'id' => '9']]]]],
            'included' => [['type' => 'mappings', 'id' => '9', 'attributes' => ['externalSite' => 'myanimelist/anime', 'externalId' => '16498']]],
        ]),
        'raw.githubusercontent.com/*' => Http::response('', 503),
    ]);
    $connection = nuvioConnection(User::factory()->create(), ['profile' => 1, 'start_event_id' => 0]);

    // Left to the queue to try again, the position where it was.
    expect(fn () => runConnectionImport($connection, NuvioSource::CODE))->toThrow(AnimeListUnavailable::class)
        ->and($connection->refresh()->state)->toBeNull();
});

test('an entry of a kind the add-on made up is reported by its kind', function () {
    Http::fake(nuvioFakes(changes: [0 => [nuvioItem('ch:42', 'channel', title: 'News 24', event: 1)]]));
    $connection = nuvioConnection(User::factory()->create(), ['profile' => 1, 'start_event_id' => 0]);

    expect(runConnectionImport($connection, NuvioSource::CODE)->errors)->toContain('News 24: Nuvio files it as channel, which this instance does not track.');
});
