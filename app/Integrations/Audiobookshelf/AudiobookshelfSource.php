<?php

namespace App\Integrations\Audiobookshelf;

use App\Integrations\Connections\ConnectionExpired;
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
 * The books an Audiobookshelf user finished or has under way, each progress
 * as AudiobookshelfRows reads it, with one request for the item behind it.
 *
 * The read position is the latest progress change seen: a run asks only
 * about the books whose progress moved since, so a run with nothing new
 * asks for the user alone. Items are asked about one by one: the batch
 * endpoint refuses the whole batch when the user may not open one of them.
 */
class AudiobookshelfSource extends ConnectionSource
{
    public const CODE = 'audiobookshelf';

    /** The user's own server, with no limit to respect: a finished book shows up within the half hour. */
    private const AUTO_IMPORT_MINUTES = 30;

    public function __construct(
        private readonly AudiobookshelfClient $client,
        private readonly AudiobookshelfRows $translate,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return AudiobookshelfClient::NAME;
    }

    public function description(): string
    {
        return __('import.audiobooks_and_ebooks_you_finished_or');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_audiobookshelf');
    }

    public function connectionService(): string
    {
        return AudiobookshelfService::CODE;
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
            return null;
        }

        $address = $this->client->address($connection);
        $since = Numbers::int($connection->stateValue('last_update'));
        $latest = $since ?? 0;

        foreach (Payload::list($this->client->me($connection, $address)['mediaProgress'] ?? null) as $progress) {
            $updated = Payload::positiveInt($progress, 'lastUpdate') ?? 0;
            $latest = max($latest, $updated);
            $id = Payload::id($progress['libraryItemId'] ?? null);

            if (($since !== null && $updated <= $since) || $id === null || ! $this->translate->wanted($progress)) {
                continue;
            }

            $item = $this->client->item($connection, $address, $id);

            yield $item === null ? new SkipRow('import.skip_audiobookshelf_item_unavailable') : $this->translate->row($progress, $item);
        }

        return $latest > 0 ? ['last_update' => (string) $latest] : null;
    }
}
