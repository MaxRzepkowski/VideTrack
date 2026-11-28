<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\ConnectMethod;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Storyteller\StorytellerClient;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * Storyteller is exercised on shapes taken from its own source (fixtures in
 * tests/Fixtures/transfer/storyteller, see the README there) — never live.
 */

beforeEach(function () {
    Sleep::fake();
});

function storytellerConnection(User $user): ServiceConnection
{
    return activeConnection($user, 'storyteller', ['url' => 'http://storyteller.lan:8001', 'key' => 'st_token-of-alex']);
}

test('Storyteller is only offered when the books module is on', function () {
    expect(app(ImportSourceRegistry::class)->has('storyteller'))->toBeFalse();
});

test('the integrations page offers Storyteller with an address and token form', function () {
    enableBooksModule();

    $this->actingAs(User::factory()->create())
        ->get(route('settings.integrations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.accounts', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === 'storyteller'
                && $source['connect']['method'] === ConnectMethod::Form->value
                && array_column($source['connect']['fields'], 'key') === ['url', 'key'])));
});

test('connecting keeps the base address and the token, under the user the token is', function () {
    enableBooksModule();
    Http::fake(['storyteller.lan:8001/api/v2/user' => Http::response(jsonFixture('transfer/storyteller/user'))]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', 'storyteller'), ['values' => ['url' => 'http://storyteller.lan:8001/api/v2/', 'key' => 'st_token-of-alex']])
        ->assertOk()
        ->assertJsonPath('connection.account', 'alex');

    $connection = $user->serviceConnections()->sole();

    expect($connection->credential('url'))->toBe('http://storyteller.lan:8001')
        ->and($connection->credential('key'))->toBe('st_token-of-alex');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://storyteller.lan:8001/api/v2/user'
        && $request->header('Authorization') === ['Bearer st_token-of-alex']);
});

test('a token is turned down once, with the reason', function (?int $status, array $body, string $token, string $message) {
    enableBooksModule();
    Http::fake(['storyteller.lan:8001/*' => Http::response($body, $status ?? 200)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', 'storyteller'), ['values' => ['url' => 'http://storyteller.lan:8001', 'key' => $token]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message]);

    expect(requestsTo('/api/v2/user'))->toBe(str_starts_with($token, StorytellerClient::TOKEN_PREFIX) ? 1 : 0);
})->with([
    'not a Storyteller token' => [null, [], 'abc123', 'A Storyteller API token starts with st_.'],
    'a wrong token' => [401, ['message' => 'Not authenticated'], 'st_wrong', 'Storyteller did not accept this API key.'],
    'a token for reading only' => [403, [], 'st_reading', 'This token has reading access only. Make one with Full access.'],
    'a user who may not list books' => [200, ['username' => 'alex', 'permissions' => ['bookList' => false]], 'st_token-of-alex', 'Your Storyteller user may not list the books.'],
]);

test('a run reads the books read and under way; a status of the user\'s own or the default says nothing', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);
    Http::fake(['storyteller.lan:8001/api/v2/books' => Http::response(jsonFixture('transfer/storyteller/books'))]);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $user = User::factory()->create();

    $taskRun = runConnectionImport(storytellerConnection($user), 'storyteller');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        // Placed by the Hardcover id its readaloud carries; read on the day its position reached the end.
        ->and($user->plays()->where('media_item_id', $dune->id)->sole()->watched_at->toIso8601ZuluString())->toBe('2026-09-20T21:20:00Z')
        // By the e-book's ISBN; in progress since its first position.
        ->and($user->mediaStates()->where('media_item_id', $hobbit->id)->sole()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->plays()->count())->toBe(1)
        ->and($taskRun->errors)->toBe(['Nowhere Book: no match for an entry without ids, nor by name.']);
});

test('the same run twice adds nothing', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);
    Http::fake(['storyteller.lan:8001/api/v2/books' => Http::response(jsonFixture('transfer/storyteller/books'))]);
    MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $connection = storytellerConnection(User::factory()->create());

    runConnectionImport($connection, 'storyteller');
    $again = runConnectionImport($connection, 'storyteller');

    expect($again->summary)->toContain('plays: 0', 'statuses: 0');
});

test('a book read opened again later is still read once', function () {
    enableBooksModule();
    $books = jsonFixture('transfer/storyteller/books');
    $later = $books;
    $later[0]['position']['timestamp'] += 86_400_000;
    $answers = [Http::response($books), Http::response($later)];
    Http::fake(['storyteller.lan:8001/api/v2/books' => function () use (&$answers) {
        return array_shift($answers);
    }]);
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    $user = User::factory()->create();
    $connection = storytellerConnection($user);

    runConnectionImport($connection, 'storyteller');
    runConnectionImport($connection, 'storyteller');

    expect($user->plays()->where('media_item_id', $dune->id)->count())->toBe(1);
});

test('a revoked token expires the connection and is not retried', function () {
    enableBooksModule();
    Http::fake(['storyteller.lan:8001/*' => Http::response(['message' => 'Not authenticated'], 401)]);
    $connection = storytellerConnection(User::factory()->create());

    $run = runConnectionImport($connection, 'storyteller');

    expect($run->status)->toBe(TaskRunStatus::Failure)
        ->and($run->summary)->toContain('The server stopped accepting this API key.')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(requestsTo('/api/v2/books'))->toBe(1);
});
