<?php

use App\Actions\Library\MarkWatched;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Tracearr\TracearrUnavailable;
use App\Jobs\RunImport;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\TaskRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * Tracearr is exercised on shapes taken from its own source (fixtures in
 * tests/Fixtures/transfer/tracearr, see the README there) — never live.
 */

beforeEach(function () {
    Sleep::fake();
    // The Tracearr these tests talk to stands in the local network, as one
    // usually does; the operator lets accounts reach it.
    config(['tracker.integrations.private_hosts' => 'tracearr.lan']);
});

const TRACEARR_ME = '7d9c1b8e-0000-4000-8000-000000000001';

function tracearrConnection(User $user, array $attributes = []): ServiceConnection
{
    return ServiceConnection::factory()->create([
        'user_id' => $user->id,
        'service' => 'tracearr',
        'credentials' => ['url' => 'http://tracearr.lan:3000', 'key' => 'trr_pub_secret'],
        'settings' => ['user_id' => TRACEARR_ME],
        'status' => ConnectionStatus::Active,
        'account_label' => 'alex',
        ...$attributes,
    ]);
}

function runTracearrImport(ServiceConnection $connection): TaskRun
{
    $taskRun = TaskRun::queueTask('import-tracearr', $connection->user_id);

    app()->call([
        new RunImport($taskRun, 'tracearr', ImportInput::connection($connection->id), ImportSelection::everything()),
        'handle',
    ]);

    return $taskRun->refresh();
}

/**
 * The Matrix (603) and Game of Thrones (1399) with season 1's first two
 * episodes, stored locally.
 *
 * @return array{matrix: MediaItem, got: MediaItem}
 */
function tracearrCatalogue(): array
{
    $show = makeShowTree([1 => ['2011-04-17', '2011-04-24']]);
    $show->update(['provider_ref' => '1399']);

    foreach (MediaItem::query()->where('parent_id', $show->id)->get() as $season) {
        $season->update(['provider_ref' => "1399:{$season->number}"]);

        foreach (MediaItem::query()->where('parent_id', $season->id)->get() as $episode) {
            $episode->update(['provider_ref' => "1399:{$season->number}:{$episode->number}"]);
        }
    }

    return ['matrix' => MediaItem::factory()->movie()->create(['provider_ref' => '603']), 'got' => $show];
}

/**
 * Two history pages joined by a cursor, and the show behind both episodes.
 *
 * @return array<string, mixed>
 */
function tracearrHistoryFakes(): array
{
    return [
        'tracearr.lan:3000/api/v2/public/history*' => fn (Request $request) => isset($request['cursor'])
            ? Http::response(jsonFixture('transfer/tracearr/history-2'))
            : Http::response(jsonFixture('transfer/tracearr/history-1')),
        'tracearr.lan:3000/api/v2/public/media/*' => Http::response(jsonFixture('transfer/tracearr/media-show')),
        ...providerDictionaryFakes(),
    ];
}

function tracearrRequests(string $path): int
{
    return Http::recorded(fn (Request $request) => str_contains(parse_url($request->url(), PHP_URL_PATH) ?? '', $path))->count();
}

test('the import page offers Tracearr with an address and key form', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/import')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === 'tracearr'
                && $source['connect']['method'] === 'form'
                && array_column($source['connect']['fields'], 'key') === ['url', 'key'])));
});

test('a key without the Tracearr prefix is turned down without asking Tracearr', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/tracearr/verify', ['values' => ['url' => 'http://tracearr.lan:3000', 'key' => 'abc123']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => 'A Tracearr API key starts with trr_pub_.']);

    Http::assertNothingSent();
});

test('an address that is not http is turned down', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', ['service' => 'tracearr']), ['values' => ['url' => 'file:///etc/passwd', 'key' => 'trr_pub_x']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['values.url' => 'Enter a web address starting with http:// or https://.']);

    Http::assertNothingSent();
});

test('a rejected key is asked about once', function (int $status, string $message) {
    Http::fake(['tracearr.lan:3000/*' => Http::response(['message' => 'nope'], $status)]);

    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/tracearr/verify', ['values' => ['url' => 'http://tracearr.lan:3000', 'key' => 'trr_pub_bad']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message]);

    expect(tracearrRequests('/users'))->toBe(1);
})->with([
    'wrong key' => [401, 'Tracearr did not accept this API key.'],
    'not the owner' => [403, 'This API key does not belong to the Tracearr owner account.'],
    'not Tracearr' => [404, 'There is no Tracearr API at this address.'],
]);

test('a good key lists the people Tracearr watches', function () {
    Http::fake(['tracearr.lan:3000/api/v2/public/users*' => Http::response(jsonFixture('transfer/tracearr/users'))]);

    $this->actingAs(User::factory()->create())
        ->postJson('/settings/connections/tracearr/verify', ['values' => ['url' => 'http://tracearr.lan:3000/', 'key' => 'trr_pub_secret']])
        ->assertOk()
        ->assertJsonPath('choices.0', ['id' => TRACEARR_ME, 'label' => 'alex'])
        ->assertJsonPath('choices.1.label', 'guest');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://tracearr.lan:3000/api/v2/public/users?pageSize=100'
        && $request->header('Authorization') === ['Bearer trr_pub_secret']);
});

test('connecting keeps the base address, the key and the picked person', function () {
    Http::fake(['tracearr.lan:3000/api/v2/public/users*' => Http::response(jsonFixture('transfer/tracearr/users'))]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/settings/connections/tracearr', [
            'values' => ['url' => 'http://tracearr.lan:3000/api/v2/public/', 'key' => 'trr_pub_secret'],
            'choice' => TRACEARR_ME,
        ])
        ->assertOk()
        ->assertJsonPath('connection.account', 'alex');

    $connection = $user->serviceConnections()->sole();

    expect($connection->credential('url'))->toBe('http://tracearr.lan:3000')
        ->and($connection->credential('key'))->toBe('trr_pub_secret')
        ->and($connection->setting('user_id'))->toBe(TRACEARR_ME);
});

test('a run reads the finished films and episodes of that one person', function () {
    Http::fake(tracearrHistoryFakes());
    ['matrix' => $matrix] = tracearrCatalogue();
    $user = User::factory()->create();
    $connection = tracearrConnection($user);

    $this->freezeTime(function () use ($connection, $user, $matrix) {
        $taskRun = runTracearrImport($connection);

        expect($taskRun->status)->toBe(TaskRunStatus::Success)
            ->and($user->plays()->where('media_item_id', $matrix->id)->sole()->watched_at->toIso8601String())->toBe('2026-09-20T21:16:00+00:00');

        $episodes = $user->plays()->whereHas('mediaItem', fn ($q) => $q->where('type', 'episode'))
            ->with('mediaItem')->orderBy('watched_at')->get();

        expect($episodes->map(fn ($play) => $play->mediaItem->provider_ref)->all())->toBe(['1399:1:1', '1399:1:2'])
            ->and($taskRun->errors)->toContain('Home Video (2019): Tracearr knows no TMDB, IMDb or TVDB id for it.');

        // Only this person, only finished viewings; the first run has no since.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/history')
            && $request['user_id'] === TRACEARR_ME
            && $request['watched'] === 'true'
            && ! isset($request['since']));

        // One lookup for the show behind both episodes.
        expect(tracearrRequests('/media/'))->toBe(1)
            ->and(tracearrRequests('/history'))->toBe(2)
            ->and($connection->refresh()->state)->toBe(['since' => now()->subDay()->toIso8601ZuluString()]);
    });
});

test('the next run starts from the saved position', function () {
    Http::fake(tracearrHistoryFakes());
    tracearrCatalogue();
    $connection = tracearrConnection(User::factory()->create(), ['state' => ['since' => '2026-09-19T08:00:00Z']]);

    runTracearrImport($connection);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/history')
        && $request['since'] === '2026-09-19T08:00:00Z');
});

test('a viewing a webhook already logged is not logged again', function () {
    Http::fake(tracearrHistoryFakes());
    ['matrix' => $matrix] = tracearrCatalogue();
    $user = User::factory()->create();

    // The media server's own webhook saw the same viewing end a few minutes apart.
    app(MarkWatched::class)->execute($user, $matrix, WatchedAt::at(CarbonImmutable::parse('2026-09-20T21:10:00Z')));

    runTracearrImport(tracearrConnection($user));

    expect($user->plays()->where('media_item_id', $matrix->id)->count())->toBe(1);
});

test('the same run twice adds nothing', function () {
    Http::fake(tracearrHistoryFakes());
    tracearrCatalogue();
    $user = User::factory()->create();
    $connection = tracearrConnection($user);

    runTracearrImport($connection);
    $plays = $user->plays()->count();
    $connection->update(['state' => null]);
    runTracearrImport($connection);

    expect($user->plays()->count())->toBe($plays)->and($plays)->toBe(3);
});

test('a revoked key expires the connection and is not retried', function () {
    Http::fake(['tracearr.lan:3000/*' => Http::response(['message' => 'Invalid API key'], 401)]);
    $user = User::factory()->create();
    $connection = tracearrConnection($user);

    $job = new RunImport(TaskRun::queueTask('import-tracearr', $user->id), 'tracearr', ImportInput::connection($connection->id), ImportSelection::everything());

    app()->call([$job, 'handle']);

    $run = TaskRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(TaskRunStatus::Failure)
        ->and($run->summary)->toContain('Tracearr stopped accepting this API key.');

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(tracearrRequests('/history'))->toBe(1);
});

test('a busy Tracearr is waited out', function () {
    $answers = [Http::response([], 429), Http::response(['data' => [], 'meta' => ['nextCursor' => null, 'pageSize' => 100]])];
    Http::fake(['tracearr.lan:3000/*' => function () use (&$answers) {
        return array_shift($answers);
    }]);

    expect(runTracearrImport(tracearrConnection(User::factory()->create()))->status)->toBe(TaskRunStatus::Success)
        ->and(tracearrRequests('/history'))->toBe(2);

    Sleep::assertSleptTimes(1);
});

test('an ordinary account cannot point the server at an address in its private network', function (string $address) {
    config(['tracker.integrations.private_hosts' => '']);
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', ['service' => 'tracearr']), ['values' => ['url' => $address, 'key' => 'trr_pub_secret']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['values.url' => __('import.this_address_is_in_a_private_network')]);

    Http::assertNothingSent();
})->with([
    'the server itself' => 'http://localhost:3000',
    'a local network name' => 'http://tracearr.lan:3000',
    'a cloud metadata endpoint' => 'http://169.254.169.254',
    'IPv6 loopback' => 'http://[::1]:3000',
    'a name with one private address among public ones' => 'http://rebound.example.com',
]);

test('an administrator may connect a Tracearr in the local network', function () {
    config(['tracker.integrations.private_hosts' => '']);
    Http::fake(['tracearr.lan:3000/*' => Http::response(['data' => [['id' => TRACEARR_ME, 'username' => 'alex']], 'meta' => []])]);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('settings.connections.verify', ['service' => 'tracearr']), ['values' => ['url' => 'http://tracearr.lan:3000', 'key' => 'trr_pub_secret']])
        ->assertOk();
});

test('a public address is open to every account, and a redirect it answers with is not followed', function () {
    Http::fake([
        'tracearr.example.com/*' => Http::response(null, 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        '169.254.169.254/*' => Http::response(['data' => []]),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', ['service' => 'tracearr']), ['values' => ['url' => 'https://tracearr.example.com', 'key' => 'trr_pub_secret']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => __('import.tracearr_could_not_be_read_at')]);

    Http::assertSentCount(1);
});

test('a run checks the address again, so a host the operator stopped allowing is no longer read', function () {
    $connection = tracearrConnection(User::factory()->create());
    config(['tracker.integrations.private_hosts' => '']);
    Http::fake();

    runTracearrImport($connection);

    Http::assertNothingSent();
    // Only connecting again with an allowed address fixes it.
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Expired);
});

test('a run whose address resolves to nothing this time is tried again, not given up', function () {
    $connection = tracearrConnection(User::factory()->create(), [
        'credentials' => ['url' => 'http://gone.lan:3000', 'key' => 'trr_pub_secret'],
    ]);
    config(['tracker.integrations.private_hosts' => 'gone.lan']);
    Http::fake();

    expect(fn () => runTracearrImport($connection))->toThrow(TracearrUnavailable::class);
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});
