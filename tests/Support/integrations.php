<?php

use App\Integrations\Connections\ConnectionStatus;
use App\Integrations\Connections\DeviceGrantClient;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Import\Rows\ImportRow;
use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use App\Integrations\VideTrack\Backup\BackupWriter;
use App\Jobs\RunImport;
use App\Models\ServiceConnection;
use App\Models\TaskRun;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;
use Tests\Fakes\FakeImportSource;
use Tests\Fakes\FakeScrobbleSource;

// Imports and scrobble endpoints, run the way the queue and the webhook would.

/**
 * Registers the test scrobble source and hands back an endpoint pointed at
 * it. Same lazy-singleton trick as the media modules: the registry resolves
 * once, so registering before the first resolve is enough.
 */
function fakeScrobbleEndpoint(User $user, array $attributes = []): WebhookEndpoint
{
    app(ScrobbleSourceRegistry::class)->register(FakeScrobbleSource::CODE, FakeScrobbleSource::class);

    return scrobbleEndpoint($user, FakeScrobbleSource::CODE, ['label' => 'Test endpoint', ...$attributes]);
}

/**
 * An address for one of the real sources, which register themselves.
 *
 * @param  array<string, mixed>  $attributes
 */
function scrobbleEndpoint(User $user, string $source, array $attributes = []): WebhookEndpoint
{
    return WebhookEndpoint::factory()->for($user)->create(['source' => $source, ...$attributes]);
}

/**
 * The user's backup as the download writes it, decoded.
 *
 * @return array<string, mixed>
 */
function backupOf(User $user): array
{
    $out = fopen('php://temp', 'w+');
    assert($out !== false);

    app(BackupWriter::class)->write($user, $out);
    rewind($out);

    return json_decode((string) stream_get_contents($out), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Runs one import synchronously over a file already put on the fake local
 * disk, the way the queue would, and hands back the refreshed task run.
 */
function runImportFile(User $user, string $source, string $path, ?ImportSelection $selection = null): TaskRun
{
    $taskRun = TaskRun::queueTask(ImportSourceRegistry::taskName($source), $user->id);

    app()->call([
        new RunImport($taskRun, $source, ImportInput::file(Storage::disk('local')->path($path)), $selection ?? ImportSelection::everything()),
        'handle',
    ]);

    return $taskRun->refresh();
}

/**
 * Registers the test source and runs it synchronously with $rows.
 *
 * @param  list<ImportRow>  $rows
 */
function runFakeImport(User $user, array $rows, string $username = 'someone'): TaskRun
{
    app(ImportSourceRegistry::class)->register(FakeImportSource::CODE, FakeImportSource::class);
    FakeImportSource::$rows = $rows;

    $taskRun = TaskRun::queueTask('import-fake', $user->id);

    app()->call([
        new RunImport($taskRun, FakeImportSource::CODE, ImportInput::credentials(['username' => $username]), ImportSelection::everything()),
        'handle',
    ]);

    return $taskRun->refresh();
}

/** A fixture file under tests/Fixtures/transfer put on the fake local disk; its path there. */
function putTransferFixture(string $source, string $name): string
{
    Storage::fake('local');
    Storage::disk('local')->put("imports/{$name}", (string) file_get_contents(base_path("tests/Fixtures/transfer/{$source}/{$name}")));

    return "imports/{$name}";
}

/**
 * A ZIP of these files, path in the archive => body, put on the fake local
 * disk as an upload; its path there.
 *
 * @param  array<string, string>  $files
 */
function putZip(array $files): string
{
    $zipPath = scratchPath('zip', '.zip');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }

    $zip->close();

    Storage::fake('local');
    Storage::disk('local')->put('imports/export.zip', (string) file_get_contents($zipPath));

    return 'imports/export.zip';
}

/** A fixture directory under tests/Fixtures/transfer packed as the ZIP a service hands out, as {@see putZip()} puts it. */
function putZipFixture(string $directory): string
{
    $root = base_path("tests/Fixtures/transfer/{$directory}");
    $files = [];

    foreach ((new Finder)->files()->in($root)->sortByName() as $file) {
        $files[str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
    }

    return putZip($files);
}

/**
 * A working connection of $user to $service, with what the service gave.
 *
 * @param  array<string, string|int>  $credentials
 * @param  array<string, mixed>  $attributes
 */
function activeConnection(User $user, string $service, array $credentials, array $attributes = []): ServiceConnection
{
    return ServiceConnection::factory()->create([
        'user_id' => $user->id,
        'service' => $service,
        'credentials' => $credentials,
        'status' => ConnectionStatus::Active,
        'account_label' => 'alex',
        ...$attributes,
    ]);
}

/**
 * A connection made through the device flow, its access token well short
 * of the refresh margin unless $credentials say otherwise.
 *
 * @param  array<string, string|int>  $credentials
 * @param  array<string, mixed>  $attributes
 */
function deviceConnection(User $user, string $service, array $credentials = [], array $attributes = []): ServiceConnection
{
    return activeConnection($user, $service, [
        'access_token' => 'access-of-alex-one',
        'refresh_token' => 'refresh-of-alex-one',
        'expires_at' => now()->addSeconds(DeviceGrantClient::REFRESH_MARGIN_SECONDS * 2)->getTimestamp(),
        ...$credentials,
    ], $attributes);
}

/** Runs one import through a connection synchronously, the way the queue would; the refreshed task run. */
function runConnectionImport(ServiceConnection $connection, string $source): TaskRun
{
    $taskRun = TaskRun::queueTask(ImportSourceRegistry::taskName($source), $connection->user_id);

    app()->call([
        new RunImport($taskRun, $source, ImportInput::connection($connection->id), ImportSelection::everything()),
        'handle',
    ]);

    return $taskRun->refresh();
}

/** How many recorded outgoing requests went to a path containing $path. */
function requestsTo(string $path): int
{
    return Http::recorded(fn (Request $request) => str_contains((string) parse_url($request->url(), PHP_URL_PATH), $path))->count();
}
