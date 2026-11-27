<?php

use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\ConnectMethod;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\MdbList\MdbListClient;
use App\Integrations\MdbList\MdbListService;
use App\Integrations\MdbList\MdbListSource;
use App\Integrations\MdbList\MdbListUnavailable;
use App\Jobs\RunImport;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
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
    Http::preventStrayRequests();
});

function mdbListConnection(User $user): ServiceConnection
{
    return activeConnection($user, MdbListService::CODE, ['key' => 'mdblist-key-of-alex']);
}

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

test('the import page offers MDBList with a key form and where the key is, nothing for the operator to set up', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === MdbListSource::CODE
                && $source['inputKind'] === 'connection'
                && $source['connect']['method'] === ConnectMethod::Form->value
                && array_column($source['connect']['fields'], 'key') === ['key']
                && $source['notes'][0]['url'] === MdbListClient::PREFERENCES_URL)));
});

test('connecting keeps the key, under the account it belongs to', function () {
    Http::fake(['api.mdblist.com/user*' => Http::response(['user_id' => 7, 'username' => 'alex', 'plan' => 'free'])]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.verify', MdbListService::CODE), ['values' => ['key' => 'mdblist-key-of-alex']])
        ->assertOk()
        ->assertJsonPath('choices', []);

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', MdbListService::CODE), ['values' => ['key' => ' mdblist-key-of-alex ']])
        ->assertOk()
        ->assertJsonPath('connection.account', 'alex');

    expect($user->serviceConnections()->sole()->credential('key'))->toBe('mdblist-key-of-alex');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.mdblist.com/user')
        && $request['apikey'] === 'mdblist-key-of-alex'
        && $request->header('User-Agent') === [config('tracker.http.user_agent')]);
});

test('a key is turned down once, with the reason; MDBList out of reach is said so', function (?int $status, string $message) {
    Http::fake(['api.mdblist.com/*' => $status === null ? Http::failedConnection() : Http::response(['error' => 'No.'], $status)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', MdbListService::CODE), ['values' => ['key' => 'mdblist-key-of-alex']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message])
        ->assertDontSee('mdblist-key-of-alex');

    expect(requestsTo('/user'))->toBe(1);
})->with([
    'a wrong key' => [401, 'MDBList did not accept this API key.'],
    'a key MDBList holds back' => [403, 'MDBList did not accept this API key.'],
    'a request MDBList refuses' => [400, 'MDBList answered 400 to /user.'],
    'failing' => [503, 'MDBList answered 503; try again later.'],
    'unreachable' => [null, 'MDBList is unreachable: Could not resolve host: api.mdblist.com'],
]);

test('the account comes over: viewings, ratings, a dropped show, the watchlist and the lists', function () {
    $items = mdbListCatalogue();
    $user = User::factory()->create();
    fakeMdbListAccount();

    $taskRun = runConnectionImport(mdbListConnection($user), MdbListSource::CODE);

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
        && data_get($request->data(), 'apikey') === 'mdblist-key-of-alex');
    expect(requestsTo('/sync/watched'))->toBe(2);
});

test('reading the same account again adds nothing', function () {
    mdbListCatalogue();
    $connection = mdbListConnection(User::factory()->create());
    fakeMdbListAccount();

    runConnectionImport($connection, MdbListSource::CODE);
    $again = runConnectionImport($connection, MdbListSource::CODE);

    expect($again->status)->toBe(TaskRunStatus::Success)
        ->and($again->summary)->toContain('plays: 0', 'ratings: 0', 'list items: 0');
});

test('a 429 leaves a fresh run for as long as MDBList asks, and nothing is asked again meanwhile', function () {
    Http::fake(['api.mdblist.com/*' => Http::response(['error' => 'API rate limit exceeded!'], 429, ['Retry-After' => '120'])]);
    $connection = mdbListConnection(User::factory()->create());

    expect(postponedBy($connection->user, MdbListSource::CODE, ImportInput::connection($connection->id)))->toBe(120)
        ->and(TaskRun::query()->sole()->status)->not->toBe(TaskRunStatus::Failure)
        ->and(requestsTo('/sync/watched'))->toBe(1);
});

test('a wait longer than the run waits out ends it with the reason', function () {
    Http::fake(['api.mdblist.com/*' => Http::response(['error' => 'Daily API limit exceeded!'], 429, ['Retry-After' => (string) (RunImport::LONGEST_BUSY_WAIT_SECONDS + 1)])]);

    $connection = mdbListConnection(User::factory()->create());
    $taskRun = runConnectionImport($connection, MdbListSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('MDBList asked to wait before more requests. Try again later.')
        ->and($connection->refresh()->last_error)->toBe('MDBList asked to wait before more requests. Try again later.')
        ->and(requestsTo('/sync/watched'))->toBe(1);
});

test('an answered 4xx other than 401 is final', function () {
    Http::fake(['api.mdblist.com/*' => Http::response(['error' => 'Invalid request'], 400)]);

    $taskRun = runConnectionImport(mdbListConnection(User::factory()->create()), MdbListSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe('MDBList answered 400 to /sync/watched.')
        ->and(requestsTo('/sync/watched'))->toBe(1);
});

test('a key MDBList no longer honours expires the connection, without asking again', function () {
    $connection = mdbListConnection(User::factory()->create());
    Http::fake(['api.mdblist.com/*' => Http::response(['error' => 'Invalid API key'], 401)]);

    $taskRun = runConnectionImport($connection, MdbListSource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toContain('MDBList stopped accepting this API key. Connect again with a current one.')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(requestsTo('/sync/watched'))->toBe(1);
});

test('MDBList out of reach ends the run without the key in the reason', function () {
    Http::fake(['api.mdblist.com/*' => Http::failedConnection()]);

    expect(fn () => runConnectionImport(mdbListConnection(User::factory()->create()), MdbListSource::CODE))
        ->toThrow(fn (MdbListUnavailable $e) => expect($e->wording())->toBe('MDBList is unreachable: Could not resolve host: api.mdblist.com')->not->toContain('mdblist-key-of-alex'));
});

test('a connection without a key expires without asking MDBList', function () {
    Http::fake();
    $connection = deviceConnection(User::factory()->create(), MdbListService::CODE);

    expect(runConnectionImport($connection, MdbListSource::CODE)->status)->toBe(TaskRunStatus::Failure)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired);

    Http::assertNothingSent();
});

test('a run reads as an import from MDBList on the Tasks page', function () {
    $user = User::factory()->create();
    TaskRun::queueTask(ImportSourceRegistry::taskName(MdbListSource::CODE), $user->id);

    $this->actingAs($user)
        ->getJson(route('settings.tasks.runs'))
        ->assertJsonPath('runs.0.label', 'Import from MDBList');
});
