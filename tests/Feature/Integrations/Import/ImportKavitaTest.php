<?php

use App\Catalog\MediaTypeRegistry;
use App\Enums\MediaUserStatus;
use App\Enums\TaskRunStatus;
use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\ConnectMethod;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Kavita\KavitaClient;
use App\Integrations\Kavita\KavitaPublicationStatus;
use App\Models\MediaItem;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

/*
 * Kavita is exercised on shapes taken from its own API schema (fixtures in
 * tests/Fixtures/transfer/kavita, see the README there) — never live.
 */

beforeEach(function () {
    Sleep::fake();
    // The server these tests talk to stands in the local network, as one
    // usually does; the operator lets accounts reach it.
    config(['tracker.integrations.private_hosts' => 'kavita.lan']);
});

function kavitaConnection(User $user): ServiceConnection
{
    return activeConnection($user, 'kavita', ['url' => 'http://kavita.lan:5000', 'key' => 'kavita-key-of-alex']);
}

/** The fixture's series Kavita knows the catalogue id of, in our catalogue already. */
function kavitaCatalogue(): void
{
    foreach (['1', '13', '2'] as $ref) {
        MediaItem::factory()->manga()->create(['provider_ref' => $ref]);
    }

    MediaItem::factory()->book()->create(['provider_ref' => '312460']);
}

/**
 * Whether a series list asks for publications that are over, the second of
 * a run's two lists. Kavita's filter numbers are its wire contract, written
 * here as its schema has them: SeriesFilterField 2 is PublicationStatus, 20
 * ReadProgress; FilterComparison 1 is GreaterThan, 5 Contains.
 */
function asksForTheOver(Request $request): bool
{
    return in_array(2, array_column((array) data_get($request->data(), 'statements'), 'field'), true);
}

/**
 * The libraries, the series begun and, of them, those read through whose
 * publication is over.
 *
 * @param  list<array<string, mixed>>|null  $begun
 */
function kavitaFakes(?array $begun = null): void
{
    fakeHardcover(['Search' => 'search']);
    Http::fake([
        'kavita.lan:5000/api/Library/libraries' => Http::response(jsonFixture('transfer/kavita/libraries')),
        'kavita.lan:5000/api/Series/all-v2*' => fn (Request $request) => Http::response(asksForTheOver($request)
            ? jsonFixture('transfer/kavita/series-publication-over')
            : ($begun ?? jsonFixture('transfer/kavita/series'))),
    ]);
}

test('the import page offers Kavita with an address and auth key form', function () {
    enableMangaModule();

    $this->actingAs(User::factory()->create())
        ->get(route('settings.import.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', fn ($sources) => collect($sources)->contains(fn ($source) => $source['code'] === 'kavita'
                && $source['connect']['method'] === ConnectMethod::Form->value
                && array_column($source['connect']['fields'], 'key') === ['url', 'key'])));
});

test('connecting keeps the base address and the key, under the user the key is', function () {
    enableMangaModule();
    Http::fake(['kavita.lan:5000/api/Account' => Http::response(jsonFixture('transfer/kavita/account'))]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.connections.connect', 'kavita'), ['values' => ['url' => 'http://kavita.lan:5000/api', 'key' => 'kavita-key-of-alex']])
        ->assertOk()
        ->assertJsonPath('connection.account', 'alex');

    $connection = $user->serviceConnections()->sole();

    expect([$connection->credential('url'), $connection->credential('key')])->toBe(['http://kavita.lan:5000', 'kavita-key-of-alex']);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://kavita.lan:5000/api/Account'
        && $request->header('x-api-key') === ['kavita-key-of-alex']);
});

test('a wrong key is asked about once', function () {
    enableMangaModule();
    Http::fake(['kavita.lan:5000/*' => Http::response('Unauthorized', 401)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('settings.connections.verify', 'kavita'), ['values' => ['url' => 'http://kavita.lan:5000', 'key' => 'wrong-key']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['connection' => 'Kavita did not accept this API key.']);

    expect(requestsTo('/api/Account'))->toBe(1);
});

test('a run reads each series by its library\'s type: a finished manga is read, a caught-up ongoing one in progress', function () {
    enableMangaModule();
    enableBooksModule();
    kavitaFakes();
    $monster = MediaItem::factory()->manga()->create(['provider_ref' => '1']);
    $onePiece = MediaItem::factory()->manga()->create(['provider_ref' => '13']);
    $berserk = MediaItem::factory()->manga()->create(['provider_ref' => '2']);
    $dune = MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    $user = User::factory()->create();

    $taskRun = runConnectionImport(kavitaConnection($user), 'kavita');

    expect($taskRun->status)->toBe(TaskRunStatus::Success)
        // Ended and read to its last page: read, with the rating.
        ->and($user->plays()->where('media_item_id', $monster->id)->count())->toBe(1)
        ->and($user->ratings()->where('media_item_id', $monster->id)->value('rating'))->toBe(9)
        // Read to its last volume, but still coming out: caught up, not finished.
        ->and($user->plays()->where('media_item_id', $onePiece->id)->exists())->toBeFalse()
        ->and($user->mediaStates()->where('media_item_id', $onePiece->id)->sole()->status)->toBe(MediaUserStatus::InProgress)
        ->and($user->mediaStates()->where('media_item_id', $berserk->id)->sole()->status)->toBe(MediaUserStatus::InProgress)
        // A book read to its end is read, whatever its publication.
        ->and($user->plays()->where('media_item_id', $dune->id)->count())->toBe(1)
        // Light novels are books, placed by their exact title; an image library says nothing.
        ->and($taskRun->errors)->toBe([
            'Kavita library “Comics” left out: this instance does not track its type.',
            'Kavita library “Indie Comics” left out: this instance does not track its type.',
            'Spice and Wolf: no match for an entry without ids, nor by name.',
        ]);

    // Two filtered lists, the way Kavita filters its own: begun, and of those the ones whose publication is over.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/Series/all-v2')
        && data_get($request->data(), 'statements') === [['field' => 20, 'comparison' => 1, 'value' => '0']]);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/Series/all-v2')
        && data_get($request->data(), 'statements') === [
            ['field' => 20, 'comparison' => 1, 'value' => '0'],
            ['field' => 2, 'comparison' => 5, 'value' => KavitaPublicationStatus::overList()],
        ]);
});

test('a later run looks only at series read since the last one', function () {
    enableMangaModule();
    enableBooksModule();
    kavitaFakes();
    kavitaCatalogue();
    $user = User::factory()->create();
    $connection = kavitaConnection($user);
    $connection->update(['state' => ['last_read' => '2026-10-01T18:00:00+00:00', 'libraries' => '1,3,4']]);

    $taskRun = runConnectionImport($connection, 'kavita');

    // A series Kavita knows the id of comes again; Spice and Wolf, placed by its title and not read since, is not searched for again.
    expect($taskRun->errors)->not->toContain('Spice and Wolf: no match for an entry without ids, nor by name.')
        ->and($connection->refresh()->stateValue('last_read'))->toBe('2026-10-05T20:00:00+00:00')
        ->and($connection->stateValue('libraries'))->toBe('1,3,4');
});

test('a series placed by its title comes again when its library is served for the first time', function () {
    enableMangaModule();
    enableBooksModule();
    kavitaFakes();
    kavitaCatalogue();
    $connection = kavitaConnection(User::factory()->create());
    // The light novel library (4) was not served before.
    $connection->update(['state' => ['last_read' => '2026-10-05T20:00:00+00:00', 'libraries' => '1,3']]);

    $taskRun = runConnectionImport($connection, 'kavita');

    expect($taskRun->errors)->toContain('Spice and Wolf: no match for an entry without ids, nor by name.');
});

test('Kavita is offered only when this instance serves a type its libraries can be', function () {
    config(['tracker.mal.client_id' => '', 'tracker.comicvine.api_key' => '', 'tracker.hardcover.api_key' => '']);
    app()->forgetInstance(ImportSourceRegistry::class);
    app()->forgetInstance(MediaTypeRegistry::class);

    expect(app(ImportSourceRegistry::class)->has('kavita'))->toBeFalse();
});

test('the same run twice adds nothing', function () {
    enableMangaModule();
    enableBooksModule();
    kavitaFakes();
    foreach (['1', '13', '2'] as $ref) {
        MediaItem::factory()->manga()->create(['provider_ref' => $ref]);
    }
    MediaItem::factory()->book()->create(['provider_ref' => '312460']);
    $connection = kavitaConnection(User::factory()->create());

    runConnectionImport($connection, 'kavita');
    $again = runConnectionImport($connection, 'kavita');

    expect($again->summary)->toContain('plays: 0', 'ratings: 0', 'statuses: 0');
});

test('a library longer than a page is read page by page', function () {
    enableMangaModule();
    $full = array_fill(0, KavitaClient::PAGE_SIZE, ['id' => 99, 'libraryId' => 5, 'pages' => 1, 'pagesRead' => 0, 'name' => 'Sketch']);
    $answers = [Http::response($full), Http::response([])];
    Http::fake([
        'kavita.lan:5000/api/Library/libraries' => Http::response(jsonFixture('transfer/kavita/libraries')),
        'kavita.lan:5000/api/Series/all-v2*' => function (Request $request) use (&$answers) {
            return asksForTheOver($request) ? Http::response([]) : array_shift($answers);
        },
    ]);

    runConnectionImport(kavitaConnection(User::factory()->create()), 'kavita');

    expect(requestsTo('/api/Series/all-v2'))->toBe(3);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'PageNumber=2') && $request->method() === 'POST');
});

test('a revoked key expires the connection and is not retried', function () {
    enableMangaModule();
    Http::fake(['kavita.lan:5000/*' => Http::response('Unauthorized', 401)]);
    $connection = kavitaConnection(User::factory()->create());

    $run = runConnectionImport($connection, 'kavita');

    expect($run->status)->toBe(TaskRunStatus::Failure)
        ->and($run->summary)->toContain('The server stopped accepting this API key.')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Expired)
        ->and(requestsTo('/api/Library/libraries'))->toBe(1);
});
