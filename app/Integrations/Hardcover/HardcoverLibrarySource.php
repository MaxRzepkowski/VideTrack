<?php

namespace App\Integrations\Hardcover;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Import\ConnectionSource;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\SetupNote;
use Generator;

/**
 * A Hardcover library read with the reader's own key, page by page;
 * each book becomes a row as HardcoverLibraryRows reads it.
 *
 * Hardcover tells nothing about what changed since a moment, so each run
 * reads the whole library; writing it again adds nothing.
 */
class HardcoverLibrarySource extends ConnectionSource implements ExplainsSetup
{
    public const CODE = 'hardcover';

    public function __construct(
        private readonly HardcoverLibraryClient $client,
        private readonly HardcoverLibraryRows $translate,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return HardcoverService::NAME;
    }

    public function description(): string
    {
        return __('import.books_you_read_are_reading_and_want_on_hardcover');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_hardcover');
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.make_a_hardcover_key_here'), HardcoverLibraryClient::newKeyUrl(), __('import.new_api_key_on_hardcover'))];
    }

    public function connectionService(): string
    {
        return HardcoverService::CODE;
    }

    /**
     * Once a day: a whole library is a request per hundred books, against
     * a daily limit the reader shares with every other app they use.
     */
    public function autoImportMinutes(): int
    {
        return 1440;
    }

    /**
     * @throws HardcoverUnavailable|HardcoverRejected|ConnectionExpired
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return;
        }

        $page = 0;

        do {
            $books = $this->client->libraryPage($connection, $page++);

            foreach ($books as $book) {
                $row = $this->translate->book($book);

                if ($row !== null) {
                    yield $row;
                }
            }
        } while (count($books) === HardcoverLibraryClient::PAGE_SIZE);
    }
}
