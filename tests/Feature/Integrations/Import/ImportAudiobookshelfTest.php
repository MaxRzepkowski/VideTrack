<?php

use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * Audiobookshelf is exercised on shapes taken from its own source (fixtures
 * in tests/Fixtures/transfer/audiobookshelf, see the README there) — never live.
 */

beforeEach(function () {
    Sleep::fake();
});

function audiobookshelfConnection(User $user, array $attributes = []): ServiceConnection
{
    return activeConnection($user, 'audiobookshelf', ['url' => 'http://abs.lan:13378', 'key' => 'key-of-alex'], $attributes);
}

/**
 * The user with every progress, the two books behind them, and a third
 * book that is gone from the server; Hardcover places them.
 */
function audiobookshelfFakes(): void
{
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);
    Http::fake([
        'abs.lan:13378/api/me' => Http::response(jsonFixture('transfer/audiobookshelf/me')),
        'abs.lan:13378/api/items/li-hobbit' => Http::response(jsonFixture('transfer/audiobookshelf/item-hobbit')),
        'abs.lan:13378/api/items/li-dune' => Http::response(jsonFixture('transfer/audiobookshelf/item-dune')),
        'abs.lan:13378/api/items/*' => Http::response('Not found', 404),
    ]);
}

test('Audiobookshelf is only offered when the books module is on', function () {
    expect(app(ImportSourceRegistry::class)->has('audiobookshelf'))->toBeFalse();
});

test('the integrations page offers Audiobookshelf with an address and key form', function () {
    enableBooksModule();

    $this->actingAs(User::factory()->create())
        ->get(route('settings.integrations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.accounts', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === 'audiobookshelf'
                && $source['connect']['method'] === 'form'
                && array_column($source['connect']['fields'], 'key') === ['url', 'key'])));
});

test('a rejected key is asked about once', function (int $status, string $message) {
    enableBooksModule();
    Http::fake(['abs.lan:13378/*' => Http::response('Unauthorized', $status)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', ['service' => 'audiobookshelf']), ['values' => ['url' => 'http://abs.lan:13378', 'key' => 'wrong-key']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => $message]);

    expect(requestsTo('/api/me'))->toBe(1);
})->with([
    'wrong key' => [401, 'Audiobookshelf did not accept this API key.'],
    'not Audiobookshelf' => [404, 'There is no Audiobookshelf API at this address.'],
]);

test('connecting keeps the base address and the key, under the user the key is', function () {
    enableBooksModule();
    Http::fake(['abs.lan:13378/api/me' => Http::response(jsonFixture('transfer/audiobookshelf/me'))]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.verify', ['service' => 'audiobookshelf']), ['values' => ['url' => 'http://abs.lan:13378/api/', 'key' => 'key-of-alex']])
        ->assertOk()
        ->assertJsonPath('choices', []);

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', ['service' => 'audiobookshelf']), ['values' => ['url' => 'http://abs.lan:13378/api/', 'key' => ' key-of-alex ']])
        ->assertOk()
        ->assertJsonPath('connection.account', 'alex');

    $connection = $user->serviceConnections()->sole();

    expect($connection->credential('url'))->toBe('http://abs.lan:13378')
        ->and($connection->credential('key'))->toBe('key-of-alex');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://abs.lan:13378/api/me'
        && $request->header('Authorization') === ['Bearer key-of-alex']);
});

test('a run reads the books finished and under way, placed by ISBN or by title and author', function () {
    enableBooksModule();
    audiobookshelfFakes();
    $hobbit = MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    $user = User::factory()->create();
    $connection = audiobookshelfConnection($user);

    $taskRun = runConnectionImport($connection, 'audiobookshelf');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        ->and($user->plays()->where('media_item_id', $hobbit->id)->sole()->watched_at->toIso8601ZuluString())->toBe('2026-09-20T21:20:00Z')
        ->and($user->mediaStates()->where('media_item_id', $dune->id)->sole()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->plays()->where('media_item_id', $dune->id)->exists())->toBeFalse()
        ->and($taskRun->errors)->toBe(['A book is gone from the Audiobookshelf server, or your user may no longer open it.']);

    // A podcast episode and a book only opened are never asked about.
    expect(requestsTo('/api/items/li-podcast'))->toBe(0)
        ->and(requestsTo('/api/items/li-opened'))->toBe(0)
        ->and($connection->refresh()->stateValue('last_update'))->toBe('1791230400000');
});

test('the next run asks only about the books whose progress moved since', function () {
    enableBooksModule();
    audiobookshelfFakes();
    $connection = audiobookshelfConnection(User::factory()->create(), ['state' => ['last_update' => '1791230400000']]);

    expect(runConnectionImport($connection, 'audiobookshelf')->status)->toBe(TaskRunStatus::Success)
        ->and(requestsTo('/api/me'))->toBe(1)
        ->and(requestsTo('/api/items/'))->toBe(0);
});

test('the same run twice adds nothing', function () {
    enableBooksModule();
    audiobookshelfFakes();
    MediaItem::factory()->book()->create(['provider_ref' => '2459845']);
    MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    $user = User::factory()->create();
    $connection = audiobookshelfConnection($user);

    runConnectionImport($connection, 'audiobookshelf');
    $connection->update(['state' => null]);
    $again = runConnectionImport($connection, 'audiobookshelf');

    expect($user->plays()->count())->toBe(1)
        ->and($again->summary)->toContain('plays: 0', 'statuses: 0');
});

test('a revoked key expires the connection and is not retried', function () {
    enableBooksModule();
    Http::fake(['abs.lan:13378/*' => Http::response('Unauthorized', 401)]);
    $connection = audiobookshelfConnection(User::factory()->create());

    $run = runConnectionImport($connection, 'audiobookshelf');

    expect($run->status)->toBe(TaskRunStatus::Failure)
        ->and($run->summary)->toContain('The server stopped accepting this API key.')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(requestsTo('/api/me'))->toBe(1);
});

test('a book the user may no longer open is left out, and the key keeps working', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn', 'Search' => 'search']);
    Http::fake([
        'abs.lan:13378/api/me' => Http::response(jsonFixture('transfer/audiobookshelf/me')),
        'abs.lan:13378/api/items/li-hobbit' => Http::response('Forbidden', 403),
        'abs.lan:13378/api/items/li-dune' => Http::response(jsonFixture('transfer/audiobookshelf/item-dune')),
        'abs.lan:13378/api/items/*' => Http::response('Not found', 404),
    ]);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    $user = User::factory()->create();
    $connection = audiobookshelfConnection($user);

    $run = runConnectionImport($connection, 'audiobookshelf');

    expect($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->errors)->toBe(['A book is gone from the Audiobookshelf server, or your user may no longer open it.'])
        ->and($user->mediaStates()->where('media_item_id', $dune->id)->sole()->status)->toBe(MediaUserStatus::InProgress)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and(requestsTo('/api/items/li-hobbit'))->toBe(1);
});
