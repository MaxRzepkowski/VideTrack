<?php

namespace Tests\Fakes;

use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Data\CredentialField;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Rows\ImportRow;
use Generator;
use Throwable;

/**
 * A source whose rows are whatever the test put in $rows, so RunImport can be
 * exercised without pretending to parse anybody's export. It asks for a
 * username, as a credentials source does.
 */
class FakeImportSource implements ReadsCredentials
{
    public const CODE = 'fake';

    /** @var list<ImportRow> */
    public static array $rows = [];

    /** The username the last run was handed. */
    public static ?string $seenUsername = null;

    /** Thrown from rows() when set, to exercise a run that breaks mid-way. */
    public static ?Throwable $failure = null;

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function description(): string
    {
        return 'A source that exists only in tests.';
    }

    public function taskLabel(): string
    {
        return 'Import from Fake';
    }

    public function credentialsSchema(): array
    {
        return [new CredentialField('username', 'Username')];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        return ImportManifest::whole([new ManifestCategory('entries', 'entries', count(self::$rows))]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        self::$seenUsername = $input->credential('username');

        if (self::$failure !== null) {
            throw self::$failure;
        }

        yield from self::$rows;
    }
}
