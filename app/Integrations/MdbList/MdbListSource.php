<?php

namespace App\Integrations\MdbList;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Import\ConnectionSource;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Models\ServiceConnection;
use App\Support\Payload;
use Generator;

/**
 * An MDBList account read through the user's connection: every viewing of
 * a movie or an episode (MDBList records a show or a season marked watched
 * as its episodes), the ratings, the shows dropped, the watchlist and the
 * user's own lists with what is on them.
 *
 * A dropped season stays out: on MDBList it only keeps the season out of
 * Up Next, it is not an abandoned attempt.
 *
 * MDBList's `since` filters on the viewing's date, so a viewing logged
 * late would be missed; each run reads the whole account instead, and
 * writing it again adds nothing.
 */
class MdbListSource extends ConnectionSource implements ExplainsSetup
{
    public const CODE = 'mdblist';

    public function __construct(
        private readonly MdbListClient $client,
        private readonly MdbListRows $translate,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return MdbListService::NAME;
    }

    public function description(): string
    {
        return __('import.movies_and_shows_you_watched_on_mdblist');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_mdblist');
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.your_mdblist_api_key_is_in'), MdbListClient::PREFERENCES_URL)];
    }

    public function connectionService(): string
    {
        return MdbListService::CODE;
    }

    /**
     * Once a day: a whole account is a handful of requests against a daily
     * limit of a thousand on a free account.
     */
    public function autoImportMinutes(): int
    {
        return 1440;
    }

    /**
     * @throws MdbListUnavailable|MdbListRejected|ConnectionExpired
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return;
        }

        // A show's TMDB id => its latest viewing, to date a dropped show by.
        $lastWatched = [];

        foreach ($this->entries($connection, '/sync/watched', ['plays' => 'all']) as [$group, $entry]) {
            $show = $this->translate->showTmdbId($group, $entry);
            $at = Payload::instant($entry['watched_at'] ?? null);

            if ($show !== null && $at !== null && (! isset($lastWatched[$show]) || $at->greaterThan($lastWatched[$show]))) {
                $lastWatched[$show] = $at;
            }

            yield $this->translate->play($group, $entry);
        }

        foreach ($this->entries($connection, '/sync/ratings') as [$group, $entry]) {
            yield $this->translate->rating($group, $entry);
        }

        foreach ($this->entries($connection, '/sync/dropped') as [$group, $entry]) {
            if ($group === MdbListGroup::Shows) {
                yield $this->translate->dropped($entry, $lastWatched);
            }
        }

        yield from $this->items($connection, '/watchlist/items', null);

        foreach (Payload::list($this->client->get($connection, '/lists/user')) as $list) {
            $id = Payload::id($list['id'] ?? null);
            $name = Payload::string($list, 'name');

            if ($id === null || $name === null) {
                continue;
            }

            $ref = new ImportListRef($name);

            yield new ListRow($ref);
            yield from $this->items($connection, "/lists/{$id}/items", $ref);
        }
    }

    /**
     * Every entry of a grouped, paged sync endpoint, with the group it came in.
     *
     * @param  array<string, string>  $query
     * @return Generator<int, array{MdbListGroup, array<array-key, mixed>}>
     */
    private function entries(ServiceConnection $connection, string $path, array $query = []): Generator
    {
        foreach ($this->client->pages($connection, $path, $query) as $page) {
            foreach (MdbListGroup::cases() as $group) {
                foreach (Payload::list($page[$group->value] ?? null) as $entry) {
                    yield [$group, $entry];
                }
            }
        }
    }

    /**
     * The titles of the watchlist or of one list, in the order MDBList keeps.
     *
     * @return Generator<int, MediaRow|SkipRow>
     */
    private function items(ServiceConnection $connection, string $path, ?ImportListRef $list): Generator
    {
        foreach ($this->client->pages($connection, $path) as $page) {
            foreach ([...Payload::list($page['movies'] ?? null), ...Payload::list($page['shows'] ?? null)] as $item) {
                $row = $this->translate->listed($item, $list);

                if ($row !== null) {
                    yield $row;
                }
            }
        }
    }
}
