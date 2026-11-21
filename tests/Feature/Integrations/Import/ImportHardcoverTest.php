<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Connections\TokenGrantClient;
use App\Integrations\Hardcover\HardcoverLibraryClient;
use App\Integrations\Hardcover\HardcoverLibrarySource;
use App\Integrations\Hardcover\HardcoverReadingStatus;
use App\Integrations\Hardcover\HardcoverService;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * Hardcover is exercised on the shapes of its own documentation (fixtures in
 * tests/Fixtures/transfer/hardcover, see the README there) — never live.
 */

beforeEach(function () {
    // Before any registry is resolved: the source and the service register
    // only on an instance with a client id and books from Hardcover.
    enableBooksModule();
    config(['tracker.hardcover.client_id' => 'hardcover-client']);
    Sleep::fake();
    Http::preventStrayRequests();
});

/**
 * The books of the library fixture, in our catalogue already.
 *
 * @return array<int, MediaItem> Hardcover book id => item
 */
function hardcoverBooks(): array
{
    $books = [];

    foreach ([101, 102, 103, 104, 105, 106, 107, 108] as $id) {
        $books[$id] = MediaItem::factory()->book()->create(['provider_ref' => (string) $id, 'release_date' => '2000-01-01']);
    }

    return $books;
}

function isLibraryQuery(Request $request): bool
{
    return str_contains($request->url(), '/v1/graphql') && str_contains((string) data_get($request->data(), 'query'), 'query Library');
}

test('without a client id the Hardcover import is not offered at all', function () {
    config(['tracker.hardcover.client_id' => '']);
    app()->forgetInstance(ImportSourceRegistry::class);
    app()->forgetInstance(ConnectionRegistry::class);

    expect(app(ImportSourceRegistry::class)->has(HardcoverLibrarySource::CODE))->toBeFalse()
        ->and(app(ConnectionRegistry::class)->has(HardcoverService::CODE))->toBeFalse();
});

test('without books from Hardcover there is nothing to import into', function () {
    config(['tracker.hardcover.api_key' => '']);
    app()->forgetInstance(ImportSourceRegistry::class);

    expect(app(ImportSourceRegistry::class)->has(HardcoverLibrarySource::CODE))->toBeFalse();
});

test('the import page offers Hardcover with a code to type at hardcover.app', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === HardcoverLibrarySource::CODE
                && $source['inputKind'] === 'connection'
                && $source['connect']['method'] === 'device'
                && $source['connect']['name'] === HardcoverService::NAME)));
});

test('connecting asks Hardcover for a device code that reads the profile and the library only', function () {
    Http::fake(['api.hardcover.app/oauth2/device' => Http::response(jsonFixture('transfer/hardcover/device'))]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.device.begin', HardcoverService::CODE))
        ->assertOk()
        ->assertJsonPath('userCode', 'ABCD-1234')
        ->assertJsonPath('verificationUri', 'https://hardcover.app/link')
        ->assertDontSee('device-code-of-alex');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.hardcover.app/oauth2/device'
        && $request->method() === 'POST'
        && $request['client_id'] === 'hardcover-client'
        && $request['scope'] === 'read:me:content read:library'
        && ! isset($request['client_secret'])
        && $request->header('User-Agent') === [config('tracker.http.user_agent')]);
});

test('an approved code becomes a connection named after the Hardcover account', function () {
    $polls = 0;

    Http::fake([
        'api.hardcover.app/oauth2/device' => Http::response(jsonFixture('transfer/hardcover/device')),
        'api.hardcover.app/oauth2/token' => function () use (&$polls) {
            return $polls++ === 0
                ? Http::response(['error' => 'authorization_pending'], 400)
                : Http::response(['access_token' => 'access-of-alex-new', 'refresh_token' => 'refresh-of-alex-new', 'token_type' => 'Bearer', 'expires_in' => 604800]);
        },
        'api.hardcover.app/v1/graphql' => Http::response(['data' => ['me' => [['username' => 'alex']]]]),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user)->postJson(route('settings.connections.device.begin', HardcoverService::CODE));

    $this->travel(5)->seconds();
    $this->postJson(route('settings.connections.device.poll', HardcoverService::CODE))->assertJsonPath('status', 'pending');

    $this->travel(5)->seconds();
    $this->postJson(route('settings.connections.device.poll', HardcoverService::CODE))
        ->assertJsonPath('status', 'connected')
        ->assertJsonPath('connection.account', 'alex');

    $connection = $user->serviceConnections()->sole();

    expect($connection->credential('access_token'))->toBe('access-of-alex-new')
        ->and($connection->credential('refresh_token'))->toBe('refresh-of-alex-new')
        ->and((int) $connection->credential('expires_at'))->toBeGreaterThan(now()->addDays(6)->getTimestamp());

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/token')
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:device_code'
        && $request['device_code'] === 'device-code-of-alex'
        && $request['client_id'] === 'hardcover-client');
});

test('a refused approval ends the attempt', function () {
    Http::fake([
        'api.hardcover.app/oauth2/device' => Http::response(jsonFixture('transfer/hardcover/device')),
        'api.hardcover.app/oauth2/token' => Http::response(['error' => 'access_denied'], 400),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user)->postJson(route('settings.connections.device.begin', HardcoverService::CODE));

    $this->travel(5)->seconds();
    $this->postJson(route('settings.connections.device.poll', HardcoverService::CODE))->assertJsonPath('status', 'expired');

    expect($user->serviceConnections()->exists())->toBeFalse();
});

test('a client id Hardcover does not know is turned down once, with the reason', function () {
    Http::fake(['api.hardcover.app/oauth2/device' => Http::response(['error' => 'invalid_client'], 401)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.device.begin', HardcoverService::CODE))
        ->assertJsonValidationErrors(['connection' => 'Hardcover did not accept this instance.']);

    expect(requestsTo('/oauth2/device'))->toBe(1);
});

test('the library comes over: reads, ratings, what is being read, on hold, dropped and wanted', function () {
    $books = hardcoverBooks();
    $user = User::factory()->create();

    Http::fake(['api.hardcover.app/v1/graphql' => Http::response(jsonFixture('transfer/hardcover/library'))]);

    $taskRun = runConnectionImport(deviceConnection($user, HardcoverService::CODE), HardcoverLibrarySource::CODE);

    $plays = fn (int $id, PlayOutcome $outcome = PlayOutcome::Completed) => $user->plays()
        ->where('media_item_id', $books[$id]->id)
        ->where('outcome', $outcome->value)
        ->orderBy('watched_at')
        ->get()
        ->map(fn ($play) => $play->watched_at->toDateString())
        ->all();

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($taskRun->errors)->toBe(['Future Status: a reading status on Hardcover this import does not know.'])
        // Every finished read-through is a read on its day.
        ->and($plays(101))->toBe(['2023-01-20', '2024-05-12'])
        ->and($user->ratings()->where('media_item_id', $books[101]->id)->value('rating'))->toBe(9)
        // Read, without a read-through: once, on the last read date.
        ->and($plays(102))->toBe(['2022-08-14'])
        ->and($user->mediaStates()->where('media_item_id', $books[103]->id)->firstOrFail()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->mediaStates()->where('media_item_id', $books[106]->id)->firstOrFail()->status)->toBe(MediaUserStatus::OnHold)
        ->and($user->watchlist()->items()->pluck('media_item_id')->all())->toBe([$books[104]->id])
        ->and($plays(105, PlayOutcome::Dropped))->toBe(['2021-04-10'])
        ->and($user->ratings()->where('media_item_id', $books[105]->id)->value('rating'))->toBe(3)
        // Ignored stays out.
        ->and($user->plays()->where('media_item_id', $books[107]->id)->exists())->toBeFalse()
        ->and($user->mediaStates()->where('media_item_id', $books[107]->id)->exists())->toBeFalse();

    Http::assertSent(fn (Request $request) => isLibraryQuery($request)
        && $request->header('Authorization') === ['Bearer access-of-alex-one']
        && data_get($request->data(), 'variables') === ['limit' => HardcoverLibraryClient::PAGE_SIZE, 'offset' => 0]);
});

test('reading the same library again adds nothing', function () {
    hardcoverBooks();
    $user = User::factory()->create();
    $connection = deviceConnection($user, HardcoverService::CODE);

    Http::fake(['api.hardcover.app/v1/graphql' => Http::response(jsonFixture('transfer/hardcover/library'))]);

    runConnectionImport($connection, HardcoverLibrarySource::CODE);
    $again = runConnectionImport($connection, HardcoverLibrarySource::CODE);

    expect($again->status)->toBe(TaskRunStatus::Success)
        ->and($again->summary)->toContain('plays: 0', 'ratings: 0', 'statuses: 0', 'list items: 0');
});

test('a library longer than a page is read page by page, a second apart', function () {
    $user = User::factory()->create();
    $page = fn (int $from, int $count) => ['data' => ['me' => [['user_books' => array_map(fn (int $id) => [
        'book_id' => $id,
        'status_id' => HardcoverReadingStatus::Ignored->value,
        'book' => ['title' => "Book {$id}"],
        'user_book_reads' => [],
    ], range($from, $from + $count - 1))]]]];

    Http::fake(['api.hardcover.app/v1/graphql' => fn (Request $request) => Http::response(
        data_get($request->data(), 'variables.offset') === 0 ? $page(1, HardcoverLibraryClient::PAGE_SIZE) : $page(1000, 1),
    )]);

    expect(runConnectionImport(deviceConnection($user, HardcoverService::CODE), HardcoverLibrarySource::CODE)->status)->toBe(TaskRunStatus::Success)
        ->and(Http::recorded(fn (Request $request) => isLibraryQuery($request))->count())->toBe(2);

    Http::assertSent(fn (Request $request) => isLibraryQuery($request) && data_get($request->data(), 'variables.offset') === HardcoverLibraryClient::PAGE_SIZE);
    Sleep::assertSleptTimes(1);
});

test('an expired access token is refreshed once, and the new refresh token kept', function () {
    hardcoverBooks();
    $user = User::factory()->create();
    $connection = deviceConnection($user, HardcoverService::CODE);

    Http::fake([
        'api.hardcover.app/oauth2/token' => Http::response(['access_token' => 'access-of-alex-two', 'refresh_token' => 'refresh-of-alex-two', 'expires_in' => 604800]),
        'api.hardcover.app/v1/graphql' => fn (Request $request) => $request->header('Authorization') === ['Bearer access-of-alex-two']
            ? Http::response(jsonFixture('transfer/hardcover/library'))
            : Http::response(['error' => 'invalid_token'], 401),
    ]);

    expect(runConnectionImport($connection, HardcoverLibrarySource::CODE)->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/oauth2/token'))->toBe(1)
        ->and($connection->refresh()->credential('access_token'))->toBe('access-of-alex-two')
        ->and($connection->credential('refresh_token'))->toBe('refresh-of-alex-two');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-of-alex-one'
        && $request['client_id'] === 'hardcover-client');
});

test('a token refreshed meanwhile by another worker is used, never refreshed a second time', function () {
    $user = User::factory()->create();
    $connection = deviceConnection($user, HardcoverService::CODE);

    Http::fake(['api.hardcover.app/v1/graphql' => function (Request $request) use ($connection) {
        if ($request->header('Authorization') === ['Bearer access-of-alex-one']) {
            ServiceConnection::query()->whereKey($connection->id)->firstOrFail()
                ->update(['credentials' => [...$connection->credentials, 'access_token' => 'access-of-alex-two', 'refresh_token' => 'refresh-of-alex-two']]);

            return Http::response(['error' => 'invalid_token'], 401);
        }

        return Http::response(['data' => ['me' => [['user_books' => []]]]]);
    }]);

    expect(runConnectionImport($connection, HardcoverLibrarySource::CODE)->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/oauth2/token'))->toBe(0);
});

test('a refresh that fails on Hardcover\'s side is not sent again, and leaves the grant alone', function () {
    $user = User::factory()->create();
    $connection = deviceConnection($user, HardcoverService::CODE, ['expires_at' => now()->addSeconds(TokenGrantClient::REFRESH_MARGIN_SECONDS - 3600)->getTimestamp()]);

    Http::fake(['api.hardcover.app/oauth2/token' => Http::response(['error' => 'Service temporarily unavailable'], 503)]);

    // Left to the queue's retries, which start over with the stored grant.
    expect(fn () => runConnectionImport($connection, HardcoverLibrarySource::CODE))->toThrow(GrantUnavailable::class, 'import.service_answered_token_refresh');

    expect(requestsTo('/oauth2/token'))->toBe(1)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and($connection->credential('refresh_token'))->toBe('refresh-of-alex-one');
});

test('a grant Hardcover no longer honours expires the connection', function () {
    $user = User::factory()->create();
    $connection = deviceConnection($user, HardcoverService::CODE);

    Http::fake([
        'api.hardcover.app/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        'api.hardcover.app/v1/graphql' => Http::response(['error' => 'invalid_token'], 401),
    ]);

    $taskRun = runConnectionImport($connection, HardcoverLibrarySource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toContain('Hardcover stopped accepting this connection. Connect your account again.')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(requestsTo('/oauth2/token'))->toBe(1);
});

test('a 429 is waited out', function () {
    $user = User::factory()->create();
    $answers = [Http::response(['error' => 'Too Many Requests'], 429), Http::response(['data' => ['me' => [['user_books' => []]]]])];

    Http::fake(['api.hardcover.app/v1/graphql' => function () use (&$answers) {
        return array_shift($answers);
    }]);

    expect(runConnectionImport(deviceConnection($user, HardcoverService::CODE), HardcoverLibrarySource::CODE)->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/v1/graphql'))->toBe(2);
});

test('a query Hardcover refuses ends the run once, without its wording', function (int $status, array $body) {
    $user = User::factory()->create();

    Http::fake(['api.hardcover.app/v1/graphql' => Http::response($body, $status)]);

    $taskRun = runConnectionImport(deviceConnection($user, HardcoverService::CODE), HardcoverLibrarySource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe("Hardcover answered {$status} and did not hand over the library.")
        ->and(requestsTo('/v1/graphql'))->toBe(1);
})->with([
    'a missing scope' => [403, ['error' => 'insufficient_scope', 'scope' => 'read:library']],
    'a GraphQL error' => [200, ['errors' => [['message' => 'field "user_books" not found in type: users']]]],
    'an answer without the library' => [200, ['data' => ['me' => []]]],
]);

test('disconnecting revokes the grant at Hardcover', function () {
    Http::fake(['api.hardcover.app/oauth2/revoke' => Http::response([])]);
    $user = User::factory()->create();
    $connection = deviceConnection($user, HardcoverService::CODE);

    $this->actingAs($user)->delete(route('settings.connections.destroy', $connection))->assertRedirect();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/revoke')
        && $request['token'] === 'refresh-of-alex-one'
        && $request['client_id'] === 'hardcover-client');

    expect(ServiceConnection::query()->count())->toBe(0);
});

test('a run reads as an import from Hardcover on the Tasks page', function () {
    $user = User::factory()->create();
    TaskRun::queueTask(ImportSourceRegistry::taskName(HardcoverLibrarySource::CODE), $user->id);

    $this->actingAs($user)
        ->getJson(route('settings.tasks.runs'))
        ->assertJsonPath('runs.0.label', 'Import from Hardcover');
});
