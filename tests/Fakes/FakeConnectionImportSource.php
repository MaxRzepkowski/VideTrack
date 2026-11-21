<?php

namespace Tests\Fakes;

use App\Integrations\Import\Contracts\ReadsFromConnection;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Rows\ImportRow;
use App\Models\ServiceConnection;
use Generator;
use Throwable;

/**
 * Reads through the fake device service. Yields $rows and returns $position
 * as its read position, or throws $failWith; records the state it started from.
 */
class FakeConnectionImportSource implements ReadsFromConnection
{
    public const CODE = 'fake-connected';

    /** @var list<ImportRow> */
    public static array $rows = [];

    /** @var array<string, mixed>|null */
    public static ?array $position = null;

    /** @var array<string, mixed>|null the connection state the last run started from */
    public static ?array $startedFrom = null;

    /** The connectable service it reads through. */
    public static string $service = FakeDeviceService::CODE;

    /** What the run ends in once its rows are out, when a test wants it to fail. */
    public static ?Throwable $failWith = null;

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Fake connected';
    }

    public function description(): string
    {
        return 'Reads through a connection.';
    }

    public function taskLabel(): string
    {
        return 'Import from Fake connected';
    }

    public function connectionService(): string
    {
        return self::$service;
    }

    public function autoImportMinutes(): int
    {
        return 60;
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        return ImportManifest::whole([]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        self::$startedFrom = ServiceConnection::query()->findOrFail($input->connectionId)->state;

        yield from self::$rows;

        if (self::$failWith !== null) {
            throw self::$failWith;
        }

        return self::$position;
    }
}
