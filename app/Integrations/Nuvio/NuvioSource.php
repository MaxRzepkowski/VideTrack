<?php

namespace App\Integrations\Nuvio;

use App\Integrations\Anime\AnimeListUnavailable;
use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Integrations\Import\ConnectionSource;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Rows\ImportRow;
use App\Models\ServiceConnection;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Generator;
use LogicException;

/**
 * What a Nuvio profile watched, from its history's change feed: the
 * documented way to follow it, where every viewing is an event in order
 * and the position is the highest event read. Nuvio counts a viewing
 * itself, past 90% of something at least a minute long, so its progress is
 * not read. A removal in Nuvio leaves the viewing here.
 *
 * The first run starts where the connection said: from the moment it was
 * made, or with the whole history (every page, then the changes made while
 * the pages were read).
 */
class NuvioSource extends ConnectionSource implements ExplainsSetup
{
    public const CODE = 'nuvio';

    /** Every quarter hour: a viewing shows up here soon after the credits. */
    public const AUTO_IMPORT_MINUTES = 15;

    public function __construct(private readonly NuvioClient $client, private readonly NuvioRows $translate) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return NuvioClient::NAME;
    }

    public function description(): string
    {
        return __('import.movies_and_episodes_you_watched_in_nuvio');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_nuvio');
    }

    public function connectionService(): string
    {
        return NuvioService::CODE;
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.nuvio_signs_in_with_your_account'))];
    }

    public function autoImportMinutes(): int
    {
        return self::AUTO_IMPORT_MINUTES;
    }

    /**
     * @throws ConnectionExpired|GrantUnavailable|OwnServerUnavailable|NuvioUnavailable|NuvioRejected|ServiceBusy|AnimeListUnavailable
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return null;
        }

        $since = $this->position($connection);

        if ($since === null) {
            $since = $this->client->cursor($connection);

            yield from $this->wholeHistory($connection);
        }

        return ['event_id' => yield from $this->changes($connection, $since)];
    }

    /** Where the last run stopped, or where the connection asked the first one to start; null for the whole history. */
    private function position(ServiceConnection $connection): ?int
    {
        $stored = $connection->stateValue('event_id') ?? $connection->setting('start_event_id');

        return match (true) {
            $stored === null => null,
            ctype_digit($stored) => (int) $stored,
            // Read as none, it would bring in the whole history the user did not ask for.
            default => throw new LogicException("A Nuvio connection's position is not an event number: {$stored}."),
        };
    }

    /**
     * @return Generator<int, ImportRow>
     *
     * @throws ConnectionExpired|GrantUnavailable|OwnServerUnavailable|NuvioUnavailable|NuvioRejected|ServiceBusy|AnimeListUnavailable
     */
    private function wholeHistory(ServiceConnection $connection): Generator
    {
        $page = 1;

        do {
            $items = $this->client->history($connection, $page++);

            foreach ($items as $item) {
                $row = $this->translate->row($item);

                if ($row !== null) {
                    yield $row;
                }
            }
        } while (count($items) === NuvioClient::HISTORY_PAGE);
    }

    /**
     * The changes after $since, page by page; the highest event read.
     *
     * @return Generator<int, ImportRow, mixed, int>
     *
     * @throws ConnectionExpired|GrantUnavailable|OwnServerUnavailable|NuvioUnavailable|NuvioRejected|ServiceBusy|AnimeListUnavailable
     */
    private function changes(ServiceConnection $connection, int $since): Generator
    {
        do {
            $events = $this->client->changes($connection, $since);

            foreach ($events as $event) {
                $since = max($since, Payload::int($event, 'event_id') ?? $since);
                // A removal in Nuvio stays a viewing here: the library only learns.
                $row = Payload::enum($event, 'operation', NuvioOperation::class) === NuvioOperation::Upsert ? $this->translate->row($event) : null;

                if ($row !== null) {
                    yield $row;
                }
            }
        } while (count($events) === NuvioClient::CHANGES_PAGE);

        return $since;
    }
}
