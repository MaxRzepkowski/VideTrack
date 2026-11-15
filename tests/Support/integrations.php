<?php

use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Import\Rows\ImportRow;
use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use App\Integrations\VideTrack\Backup\BackupWriter;
use App\Jobs\RunImport;
use App\Models\TaskRun;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Storage;
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
    $taskRun = TaskRun::queueTask("import-{$source}", $user->id);

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
