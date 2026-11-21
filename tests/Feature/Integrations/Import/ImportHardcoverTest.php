<?php

use App\Enums\MediaUserStatus;
use App\Enums\PlayOutcome;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\ConnectMethod;
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
    // only on an instance with books from Hardcover.
    enableBooksModule();
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

function hardcoverConnection(User $user): ServiceConnection
{
    return activeConnection($user, HardcoverService::CODE, ['key' => 'hardcover-key-of-alex']);
}

function isLibraryQuery(Request $request): bool
{
    return str_contains($request->url(), '/v1/graphql') && str_contains((string) data_get($request->data(), 'query'), 'query Library');
}

test('without books from Hardcover there is nothing to import into', function () {
    config(['tracker.hardcover.api_key' => '']);
    app()->forgetInstance(ImportSourceRegistry::class);

    expect(app(ImportSourceRegistry::class)->has(HardcoverLibrarySource::CODE))->toBeFalse();
});

test('the import page offers Hardcover with a key form and the link that makes the key', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === HardcoverLibrarySource::CODE
                && $source['inputKind'] === 'connection'
                && $source['connect']['method'] === ConnectMethod::Form->value
                && array_column($source['connect']['fields'], 'key') === ['key']
                && $source['notes'][0]['url'] === 'https://hardcover.app/account/api/keys/new?scope=read%3Ame%3Acontent+read%3Alibrary'
                && $source['notes'][0]['linkText'] === 'New API key on Hardcover')));
});

test('connecting keeps the key, under the reader it belongs to, also when pasted with Bearer', function () {
    Http::fake(['api.hardcover.app/v1/graphql' => Http::response(['data' => ['me' => [['username' => 'alex', 'user_books' => [['book_id' => 101]]]]]])]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.verify', HardcoverService::CODE), ['values' => ['key' => 'hardcover-key-of-alex']])
        ->assertOk()
        ->assertJsonPath('choices', []);

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', HardcoverService::CODE), ['values' => ['key' => ' Bearer hardcover-key-of-alex ']])
        ->assertOk()
        ->assertJsonPath('connection.account', 'alex');

    expect($user->serviceConnections()->sole()->credential('key'))->toBe('hardcover-key-of-alex');
    Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer hardcover-key-of-alex']);
});

test('a key is turned down once, with the reason', function (int $status, array $body, string $message) {
    Http::fake(['api.hardcover.app/v1/graphql' => Http::response($body, $status)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', HardcoverService::CODE), ['values' => ['key' => 'hardcover-key-of-alex']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message]);

    expect(requestsTo('/v1/graphql'))->toBe(1);
})->with([
    'a wrong key' => [401, ['error' => 'invalid_token'], 'Hardcover did not accept this API key.'],
    'a key without the library scope' => [403, ['error' => 'insufficient_scope', 'scope' => 'read:library'], 'This key cannot read your library. Make one with the link above.'],
    'a scope error in a 200' => [200, ['errors' => [['message' => 'field "user_books" not found in type: users']]], 'This key cannot read your library. Make one with the link above.'],
    'an answer without the library' => [200, ['data' => ['me' => []]], 'This key cannot read your library. Make one with the link above.'],
    'a request Hardcover refuses' => [400, ['error' => 'bad request'], 'Hardcover answered 400 to /v1/graphql.'],
]);

test('Hardcover out of reach or failing is said so, not taken for a wrong key', function (?int $status, string $message) {
    Http::fake(['api.hardcover.app/v1/graphql' => $status === null ? Http::failedConnection() : Http::response(['error' => 'down'], $status)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', HardcoverService::CODE), ['values' => ['key' => 'hardcover-key-of-alex']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message]);
})->with([
    'unreachable' => [null, 'Hardcover is unreachable: Could not resolve host: api.hardcover.app'],
    'failing' => [503, 'Hardcover answered 503; try again later.'],
]);

test('a connection without a key expires without asking Hardcover', function () {
    Http::fake();
    $connection = deviceConnection(User::factory()->create(), HardcoverService::CODE);

    expect(runConnectionImport($connection, HardcoverLibrarySource::CODE)->status)->toBe(TaskRunStatus::Failure)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired);

    Http::assertNothingSent();
});

test('the library comes over: reads, ratings, what is being read, on hold, dropped and wanted', function () {
    $books = hardcoverBooks();
    $user = User::factory()->create();

    Http::fake(['api.hardcover.app/v1/graphql' => Http::response(jsonFixture('transfer/hardcover/library'))]);

    $taskRun = runConnectionImport(hardcoverConnection($user), HardcoverLibrarySource::CODE);

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
        && $request->header('Authorization') === ['Bearer hardcover-key-of-alex']
        && data_get($request->data(), 'variables') === ['limit' => HardcoverLibraryClient::PAGE_SIZE, 'offset' => 0]);
});

test('reading the same library again adds nothing', function () {
    hardcoverBooks();
    $user = User::factory()->create();
    $connection = hardcoverConnection($user);

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

    expect(runConnectionImport(hardcoverConnection($user), HardcoverLibrarySource::CODE)->status)->toBe(TaskRunStatus::Success)
        ->and(Http::recorded(fn (Request $request) => isLibraryQuery($request))->count())->toBe(2);

    Http::assertSent(fn (Request $request) => isLibraryQuery($request) && data_get($request->data(), 'variables.offset') === HardcoverLibraryClient::PAGE_SIZE);
    Sleep::assertSleptTimes(1);
});

test('a key Hardcover no longer honours expires the connection, without asking again', function () {
    $user = User::factory()->create();
    $connection = hardcoverConnection($user);

    Http::fake(['api.hardcover.app/v1/graphql' => Http::response(['error' => 'invalid_token'], 401)]);

    $taskRun = runConnectionImport($connection, HardcoverLibrarySource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toContain('Hardcover stopped accepting this API key. Connect again with a current one.')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(requestsTo('/v1/graphql'))->toBe(1);
});

test('a 429 is waited out', function () {
    $user = User::factory()->create();
    $answers = [Http::response(['error' => 'Too Many Requests'], 429), Http::response(['data' => ['me' => [['user_books' => []]]]])];

    Http::fake(['api.hardcover.app/v1/graphql' => function () use (&$answers) {
        return array_shift($answers);
    }]);

    expect(runConnectionImport(hardcoverConnection($user), HardcoverLibrarySource::CODE)->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/v1/graphql'))->toBe(2);
});

test('a query Hardcover refuses ends the run once, without its wording', function (int $status, array $body) {
    $user = User::factory()->create();

    Http::fake(['api.hardcover.app/v1/graphql' => Http::response($body, $status)]);

    $taskRun = runConnectionImport(hardcoverConnection($user), HardcoverLibrarySource::CODE);

    expect($taskRun->status)->toBe(TaskRunStatus::Failure)
        ->and($taskRun->summary)->toBe("Hardcover answered {$status} and did not hand over the library.")
        ->and(requestsTo('/v1/graphql'))->toBe(1);
})->with([
    'a missing scope' => [403, ['error' => 'insufficient_scope', 'scope' => 'read:library']],
    'a GraphQL error' => [200, ['errors' => [['message' => 'field "user_books" not found in type: users']]]],
    'an answer without the library' => [200, ['data' => ['me' => []]]],
]);

test('a run reads as an import from Hardcover on the Tasks page', function () {
    $user = User::factory()->create();
    TaskRun::queueTask(ImportSourceRegistry::taskName(HardcoverLibrarySource::CODE), $user->id);

    $this->actingAs($user)
        ->getJson(route('settings.tasks.runs'))
        ->assertJsonPath('runs.0.label', 'Import from Hardcover');
});
