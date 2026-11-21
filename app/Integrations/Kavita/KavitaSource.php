<?php

namespace App\Integrations\Kavita;

use App\Catalog\MediaTypeRegistry;
use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\OwnServerNotFound;
use App\Integrations\Connections\OwnServerRejected;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Integrations\Import\ConnectionSource;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\Numbers;
use App\Support\Payload;
use Generator;

/**
 * The series a Kavita user read or is reading, each as KavitaRows reads it
 * for the type its library is made for. A library of a type this instance
 * does not serve is left out, with one line saying so.
 *
 * A run asks Kavita for the series the user has begun and, in one more
 * filtered list, for those of them whose publication is over. A series
 * Kavita knows the catalogue id of is read every run: placing it costs no
 * search, and a rating or a publication that ended since comes over. One
 * placed by its title is read only when it was read since the last run, or
 * when its library is served for the first time, so it is not searched for
 * again and again; a rating changed on it alone waits for its next reading.
 */
class KavitaSource extends ConnectionSource
{
    public const CODE = 'kavita';

    /** The user's own server: a finished series shows up within the half hour. */
    private const AUTO_IMPORT_MINUTES = 30;

    public function __construct(
        private readonly KavitaClient $client,
        private readonly KavitaRows $translate,
        private readonly MediaTypeRegistry $types,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return KavitaClient::NAME;
    }

    public function description(): string
    {
        return __('import.manga_comics_and_books_you_read_on_kavita');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_kavita');
    }

    public function connectionService(): string
    {
        return KavitaService::CODE;
    }

    public function autoImportMinutes(): int
    {
        return self::AUTO_IMPORT_MINUTES;
    }

    /**
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ConnectionExpired
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return null;
        }

        $address = $this->client->address($connection);
        $since = Payload::instant($connection->stateValue('last_read'));
        $libraries = $connection->stateValue('libraries');
        $servedBefore = $libraries === null ? [] : Numbers::ints(explode(',', $libraries));
        $latest = $since;
        $kinds = [];

        foreach ($this->client->libraries($connection, $address) as $library) {
            $id = Payload::positiveInt($library, 'id');
            $kind = Payload::intEnum($library, 'type', KavitaLibraryType::class)?->kind();

            if ($id === null || $kind === null) {
                continue;
            }

            if (! $this->types->has($kind->value)) {
                yield new SkipRow('import.skip_kavita_library_of_a_type_off', ['library' => Payload::text($library, 'name')]);

                continue;
            }

            $kinds[$id] = $kind;
        }

        $over = $this->client->begunAndOver($connection, $address);

        foreach ($this->client->begunSeries($connection, $address) as $page) {
            foreach ($page as $series) {
                $library = Payload::positiveInt($series, 'libraryId');
                $id = Payload::positiveInt($series, 'id');
                $kind = $library === null ? null : ($kinds[$library] ?? null);

                if ($kind === null || $id === null) {
                    continue;
                }

                $readAt = $this->translate->lastRead($series);
                $latest = $readAt !== null && ($latest === null || $readAt->greaterThan($latest)) ? $readAt : $latest;
                $readSince = $since === null || $readAt === null || $readAt->greaterThan($since);

                if (! $this->translate->knowsCatalogueId($series, $kind) && ! $readSince && in_array($library, $servedBefore, true)) {
                    continue;
                }

                $row = $this->translate->row($series, $kind, isset($over[$id]));

                if ($row !== null) {
                    yield $row;
                }
            }
        }

        return ['last_read' => $latest?->toIso8601String(), 'libraries' => implode(',', array_keys($kinds))];
    }
}
