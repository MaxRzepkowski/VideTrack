<?php

namespace App\Integrations\Storyteller;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\OwnServerRejected;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Integrations\Import\ConnectionSource;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use Generator;

/**
 * The books a Storyteller user read or is reading, each as StorytellerRows
 * reads it. The whole library comes in one answer, so each run reads it
 * all; writing it again adds nothing.
 */
class StorytellerSource extends ConnectionSource
{
    public const CODE = 'storyteller';

    /** The user's own server, with no limit to respect: a finished book shows up within the half hour. */
    private const AUTO_IMPORT_MINUTES = 30;

    public function __construct(
        private readonly StorytellerClient $client,
        private readonly StorytellerRows $translate,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return StorytellerClient::NAME;
    }

    public function description(): string
    {
        return __('import.books_you_read_or_are_reading_on_storyteller');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_storyteller');
    }

    public function connectionService(): string
    {
        return StorytellerService::CODE;
    }

    public function autoImportMinutes(): int
    {
        return self::AUTO_IMPORT_MINUTES;
    }

    /**
     * @throws OwnServerUnavailable|OwnServerRejected|ConnectionExpired
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return;
        }

        foreach ($this->client->books($connection, $this->client->address($connection)) as $book) {
            $row = $this->translate->row($book);

            if ($row !== null) {
                yield $row;
            }
        }
    }
}
