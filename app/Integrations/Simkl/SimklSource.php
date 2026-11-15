<?php

namespace App\Integrations\Simkl;

use App\Integrations\Import\Contracts\ReadsFromConnection;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportSelection;
use App\Models\ServiceConnection;
use App\Support\Payload;
use Generator;

/**
 * A Simkl library read through the user's connection, the way Simkl's API
 * rules require (api.simkl.org → API rules, Sync guide):
 *
 * - the first run pulls the whole library, one type after another;
 * - every later run asks GET /sync/activities first and stops right there
 *   when `all` has not moved; otherwise it pulls only what changed since
 *   (date_from = the `all` saved last time). Pulling the whole library on
 *   a timer gets the instance's client id suspended without warning.
 *
 * The saved `all` is the read position RunImport keeps on the connection.
 * Rewatch sessions are a PRO/VIP extra and are not asked for.
 */
class SimklSource implements ReadsFromConnection
{
    public const CODE = 'simkl';

    private const TYPES = ['shows', 'movies', 'anime'];

    private const LIBRARY_QUERY = [
        'extended' => 'full',
        'episode_watched_at' => 'yes',
        'include_all_episodes' => 'yes',
        'episode_tvdb_id' => 'yes',
    ];

    public function __construct(
        private readonly SimklClient $client,
        private readonly SimklRows $translate,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Simkl';
    }

    public function description(): string
    {
        return __('import.shows_movies_and_anime_you_watched');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_simkl');
    }

    public function connectionService(): string
    {
        return SimklService::CODE;
    }

    /**
     * Once a day: the run itself stops at
     * /sync/activities when nothing moved.
     */
    public function autoImportMinutes(): int
    {
        return 1440;
    }

    /**
     * Nothing is fetched to look: the library is read once, by the run.
     */
    public function analyze(ImportInput $input): ImportManifest
    {
        $connection = $this->connection($input);

        if ($connection === null || ! $connection->isActive()) {
            return ImportManifest::invalid(__('import.connect_your_account_first'));
        }

        return ImportManifest::whole([]);
    }

    /**
     * @throws SimklUnavailable
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return null;
        }

        $saved = $connection->stateValue('activities_all');
        $activities = $this->client->get($connection, '/sync/activities');
        $latest = is_string($activities['all'] ?? null) ? $activities['all'] : null;

        // Without the moment nothing tells the next run where to start, and a
        // full read every day is what gets a client id suspended: this run
        // stops here and keeps the position it had.
        if ($latest === null) {
            throw new SimklUnavailable('import.simkl_did_not_say_what_changed');
        }

        if ($saved !== null && $latest === $saved) {
            return ['activities_all' => $saved];
        }

        $libraries = $saved === null
            ? array_map(fn (string $type): array => $this->client->get($connection, "/sync/all-items/{$type}", self::LIBRARY_QUERY), self::TYPES)
            : [$this->client->get($connection, '/sync/all-items', [...self::LIBRARY_QUERY, 'date_from' => $saved])];

        foreach ($libraries as $library) {
            foreach ($this->entries($library, 'shows') as $entry) {
                yield from $this->translate->show($entry);
            }

            foreach ($this->entries($library, 'movies') as $entry) {
                yield from $this->translate->movie($entry);
            }

            foreach ($this->entries($library, 'anime') as $entry) {
                yield from $this->translate->anime($entry);
            }
        }

        return ['activities_all' => $latest];
    }

    /**
     * @param  array<array-key, mixed>  $library
     * @return list<array<array-key, mixed>>
     */
    private function entries(array $library, string $type): array
    {
        return Payload::list($library[$type] ?? null);
    }

    /** The connection the run reads through, while it still exists. */
    private function connection(ImportInput $input): ?ServiceConnection
    {
        return $input->connectionId === null ? null : ServiceConnection::query()->find($input->connectionId);
    }
}
