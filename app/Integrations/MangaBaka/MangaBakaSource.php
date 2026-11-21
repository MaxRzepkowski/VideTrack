<?php

namespace App\Integrations\MangaBaka;

use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Import\ConnectionSource;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Support\ServiceBusy;
use Generator;

/**
 * A MangaBaka library read through the user's connection, each series as
 * MangaBakaRows reads it, with the lists it is on.
 *
 * MangaBaka can list only what changed since a moment, but an entry saved
 * without a visible change moves too; each run reads the whole library
 * instead, and writing it again adds nothing.
 */
class MangaBakaSource extends ConnectionSource
{
    public const CODE = 'mangabaka';

    /** Once a day: a library is a request per hundred series. */
    private const AUTO_IMPORT_MINUTES = 1440;

    public function __construct(
        private readonly MangaBakaClient $client,
        private readonly MangaBakaRows $translate,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return MangaBakaService::NAME;
    }

    public function description(): string
    {
        return __('import.manga_you_read_are_reading_and_want_on_mangabaka');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_mangabaka');
    }

    public function connectionService(): string
    {
        return MangaBakaService::CODE;
    }

    public function autoImportMinutes(): int
    {
        return self::AUTO_IMPORT_MINUTES;
    }

    /**
     * @throws MangaBakaUnavailable|MangaBakaRejected|ServiceBusy|GrantUnavailable
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return;
        }

        // A list is named once, before the first series that comes over onto it.
        $named = [];

        foreach ($this->client->libraryPages($connection) as $page) {
            foreach ($page as $item) {
                $lists = $this->translate->lists($item);
                $row = $this->translate->entry($item, $named + $lists);

                // A list is made only for a series that comes over onto it.
                if ($row instanceof MediaRow) {
                    foreach (array_diff_key($lists, $named) as $id => $list) {
                        $named[$id] = $list;

                        yield new ListRow($list);
                    }
                }

                if ($row !== null) {
                    yield $row;
                }
            }
        }
    }
}
