<?php

use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\MdbList\MdbListClient;
use App\Integrations\MdbList\MdbListService;
use App\Integrations\MdbList\MdbListSource;
use App\Jobs\RunImport;
use App\Models\MediaItem;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/*
 * MDBList is exercised on the shapes of its own API reference (fixtures in
 * tests/Fixtures/transfer/mdblist, see the README there) — never live.
 */

beforeEach(function () {
    // Before any registry is resolved: the source and the service register
    // only on an instance with a client id.
    config(['tracker.mdblist.client_id' => 'mdblist-client']);
    Http::preventStrayRequests();
});

/**
 * The account of the fixtures, every endpoint answered; the watched
 * history comes in two pages.
 */
function fakeMdbListAccount(): void
{
    Http::fake([
        'api.mdblist.com/sync/watched*' => fn (Request $request) => Http::response(jsonFixture(
            data_get($request->data(), 'cursor') === 'page-two' ? 'transfer/mdblist/watched-2' : 'transfer/mdblist/watched-1',
        )),
        'api.mdblist.com/sync/ratings*' => Http::response(jsonFixture('transfer/mdblist/ratings')),
        'api.mdblist.com/sync/dropped*' => Http::response(jsonFixture('transfer/mdblist/dropped')),
        'api.mdblist.com/watchlist/items*' => Http::response(jsonFixture('transfer/mdblist/watchlist')),
        'api.mdblist.com/lists/user*' => Http::response(jsonFixture('transfer/mdblist/lists-user')),
        'api.mdblist.com/lists/101/items*' => Http::response(jsonFixture('transfer/mdblist/list-items')),
    ]);
}

/**
 * Batman Begins (TMDB 272), Breaking Bad (1396) with season 1's first two
 * episodes, Beetlejuice Beetlejuice (917496) and English Teacher (258902).
 *
 * @return array<string, MediaItem>
 */
function mdbListCatalogue(): array
{
    return [
        'batman' => MediaItem::factory()->movie()->create(['provider_ref' => '272']),
        'bb' => showWithRefs('1396', [1 => ['2008-01-20', '2008-01-27']]),
        'beetlejuice' => MediaItem::factory()->movie()->create(['provider_ref' => '917496']),
        'teacher' => MediaItem::factory()->show()->create(['provider_ref' => '258902']),
    ];
}

test('without a client id MDBList is not offered at all', function () {
    config(['tracker.mdblist.client_id' => '']);
    app()->forgetInstance(ImportSourceRegistry::class);
    app()->forgetInstance(ConnectionRegistry::class);

    expect(app(ImportSourceRegistry::class)->has(MdbListSource::CODE))->toBeFalse()
        ->and(app(ConnectionRegistry::class)->has(MdbListService::CODE))->toBeFalse();
});

test('the import page offers MDBList with a code to type at mdblist.com', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === MdbListSource::CODE
                && $source['inputKind'] === 'connection'
                && $source['connect']['method'] === 'device'
                && $source['connect']['name'] === MdbListService::NAME)));
});

test('connecting asks MDBList for a device code, and an approved one is named after the account', function () {
    $polls = 0;

    Http::fake([
        'api.mdblist.com/oauth/device-authorization/' => Http::response([
            'device_code' => 'device-code-of-alex',
            'user_code' => 'WXYZ-9876',
            'verification_uri' => 'https://mdblist.com/oauth/device/',
            'verification_uri_complete' => 'https://mdblist.com/oauth/device/?user_code=WXYZ-9876',
            'expires_in' => 300,
            'interval' => 5,
        ]),
        'api.mdblist.com/oauth/token/' => function () use (&$polls) {
            return $polls++ === 0
                ? Http::response(['error' => 'authorization_pending'], 400)
                : Http::response(['access_token' => 'access-of-alex-new', 'token_type' => 'Bearer', 'expires_in' => 2592000, 'refresh_token' => 'refresh-of-alex-new', 'scope' => 'write']);
        },
        'api.mdblist.com/user' => Http::response(['user_id' => 7, 'username' => 'alex']),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.device.begin', MdbListService::CODE))
        ->assertOk()
        ->assertJsonPath('userCode', 'WXYZ-9876')
        ->assertDontSee('device-code-of-alex');

    $this->travel(5)->seconds();
    $this->postJson(route('settings.connections.device.poll', MdbListService::CODE))->assertJsonPath('status', 'pending');

    $this->travel(5)->seconds();
    $this->postJson(route('settings.connections.device.poll', MdbListService::CODE))
        ->assertJsonPath('status', 'connected')
        ->assertJsonPath('connection.account', 'alex');

    expect($user->serviceConnections()->sole()->credential('refresh_token'))->toBe('refresh-of-alex-new');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.mdblist.com/oauth/device-authorization/'
        && $request['client_id'] === 'mdblist-client'
        && $request['scope'] === 'write'
        && $request->header('User-Agent') === [config('tracker.http.user_agent')]);
});

test('the account comes over: viewings, ratings, a dropped show, the watchlist and the lists', function () {
    $items = mdbListCatalogue();
    $user = User::factory()->create();
    fakeMdbListAccount();

    $taskRun = runConnectionImport(deviceConnection($user, MdbListService::CODE), MdbListSource::CODE);

    $episodes = MediaItem::query()->where('type', 'episode')->orderBy('episode_number')->pluck('id')->all();
    $season = MediaItem::query()->where('type', 'season')->sole();

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['Nowhere To Be Found: no TMDB id and no other id to look it up by; cannot resolve.'])
        ->and($user->plays()->where('media_item_id', $items['batman']->id)->where('outcome', PlayOutcome::Completed->value)->count())->toBe(2)
        ->and($user->plays()->whereIn('media_item_id', $episodes)->where('outcome', PlayOutcome::Completed->value)->count())->toBe(2)
        ->and($user->ratings()->where('media_item_id', $items['batman']->id)->value('rating'))->toBe(9)
        ->and($user->ratings()->where('media_item_id', $items['bb']->id)->value('rating'))->toBe(8)
        ->and($user->ratings()->where('media_item_id', $season->id)->value('rating'))->toBe(7)
        ->and($user->ratings()->where('media_item_id', $episodes[0])->value('rating'))->toBe(10)
        // Dropped on the day of the show's last viewing.
        ->and($user->plays()->where('media_item_id', $items['bb']->id)->where('outcome', PlayOutcome::Dropped->value)->sole()->watched_at->toDateString())->toBe('2025-10-16')
        ->and($user->watchlist()->items()->pluck('media_item_id')->sort()->values()->all())->toBe([$items['beetlejuice']->id, $items['teacher']->id])
        ->and($user->lists()->where('title', 'Halloween Night')->sole()->items()->pluck('media_item_id')->all())->toBe([$items['batman']->id]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sync/watched')
        && data_get($request->data(), 'plays') === 'all'
        && data_get($request->data(), 'limit') === (string) MdbListClient::PAGE_SIZE
        && $request->header('Authorization') === ['Bearer access-of-alex-one']);
    expect(requestsTo('/sync/watched'))->toBe(2);
});

test('reading the same account again adds nothing', function () {
    mdbListCatalogue();
    $connection = deviceConnection(User::factory()->create(), MdbListService::CODE);
    fakeMdbListAccount();

    runConnectionImport($connection, MdbListSource::CODE);
    $again = runConnectionImport($connection, MdbListSource::CODE);

    expect($again->status)->toBe(TaskRunStatus::Success)
        ->and($again->summary)->toContain('plays: 0', 'ratings: 0', 'list items: 0');
});

test('a 429 leaves a fresh run for as long as MDBList asks, and nothing is asked again meanwhile', function () {
    Http::fake(['api.mdblist.com/*' => Http::response(['error' => 'API rate limit exceeded!'], 429, ['Retry-After' => '120'])]);
    $connection = deviceConnection(User::factory()->create(), MdbListService::CODE);
    $taskRun = TaskRun::queueTask(ImportSourceRegistry::taskName(MdbListSource::CODE), $connection->user_id);
    $job = new RunImport($taskRun, MdbListSource::CODE, ImportInput::connection($connection->id), ImportSelection::everything());

    app()->call([$job, 'handle']);

    $later = $job->handOver();
    expect($taskRun->refresh()->status)->not->toBe(TaskRunStatus::Failure)
        ->and($later instanceof RunImport ? $later->delay : null)->toBe(120)
        ->and(requestsTo('/sync/watched'))->toBe(1);
});

test('a wait longer than the run waits out ends it with the reason', function () {
    Http::fake(['api.mdblist.com/*' => Http::response(['error' => 'Daily API limit exceeded!'], 429, ['Retry-After' => (string) (RunImport::LONGEST_BUSY_WAIT_SECONDS + 1)])]);

    $connection = deviceConnection(User::factory()->create(), MdbListService::CODE);
    $taskRun = runConnectionImport($connection, MdbListSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('MDBList asked to wait before more requests. Try again later.')
        ->and($connection->refresh()->last_error)->toBe('MDBList asked to wait before more requests. Try again later.')
        ->and(requestsTo('/sync/watched'))->toBe(1);
});

test('an answered 4xx other than 401 is final', function () {
    Http::fake(['api.mdblist.com/*' => Http::response(['error' => 'Invalid request'], 400)]);

    $taskRun = runConnectionImport(deviceConnection(User::factory()->create(), MdbListService::CODE), MdbListSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('MDBList answered 400 to /sync/watched.')
        ->and(requestsTo('/sync/watched'))->toBe(1);
});

test('a grant MDBList no longer honours expires the connection', function () {
    $connection = deviceConnection(User::factory()->create(), MdbListService::CODE);

    Http::fake([
        'api.mdblist.com/oauth/token/' => Http::response(['error' => 'invalid_grant'], 400),
        'api.mdblist.com/*' => Http::response(['error' => 'Invalid token'], 401),
    ]);

    $taskRun = runConnectionImport($connection, MdbListSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toContain('MDBList stopped accepting this connection. Connect your account again.')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired);
});

test('disconnecting revokes the grant at MDBList', function () {
    Http::fake(['api.mdblist.com/oauth/revoke_token/' => Http::response([])]);
    $user = User::factory()->create();
    $connection = deviceConnection($user, MdbListService::CODE);

    $this->actingAs($user)->delete(route('settings.connections.destroy', $connection))->assertRedirect();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth/revoke_token/')
        && $request['token'] === 'refresh-of-alex-one'
        && $request['client_id'] === 'mdblist-client');
});

test('a run reads as an import from MDBList on the Tasks page', function () {
    $user = User::factory()->create();
    TaskRun::queueTask(ImportSourceRegistry::taskName(MdbListSource::CODE), $user->id);

    $this->actingAs($user)
        ->getJson(route('settings.tasks.runs'))
        ->assertJsonPath('runs.0.label', 'Import from MDBList');
});
