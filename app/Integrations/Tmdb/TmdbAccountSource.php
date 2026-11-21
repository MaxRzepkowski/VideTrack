<?php

namespace App\Integrations\Tmdb;

use App\Catalog\MediaKind;
use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Import\ConnectionSource;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Models\ServiceConnection;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Generator;

/**
 * A TMDB account read through the user's session: the watchlist, the
 * favourites and the ratings of movies, shows and episodes, and the user's
 * own lists with what is on them. v3 shows another app a list only when it
 * is public (a private one answers "This resource is private."); one it
 * does not show stays out with a word.
 *
 * TMDB says nothing about what changed since a moment, so each run reads
 * the whole account; writing it again adds nothing.
 */
class TmdbAccountSource extends ConnectionSource
{
    public const CODE = 'tmdb';

    /** The account listings read, by what they hold and of which kind. */
    private const LISTINGS = [
        [TmdbListing::Watchlist, MediaKind::Movie],
        [TmdbListing::Watchlist, MediaKind::Show],
        [TmdbListing::Favorites, MediaKind::Movie],
        [TmdbListing::Favorites, MediaKind::Show],
        [TmdbListing::Rated, MediaKind::Movie],
        [TmdbListing::Rated, MediaKind::Show],
    ];

    /** The user's own lists, below the account. */
    private const LISTS = 'lists';

    /** Once a day: a whole account is a request per twenty titles. */
    private const AUTO_IMPORT_MINUTES = 1440;

    public function __construct(
        private readonly TmdbAccountClient $client,
        private readonly TmdbAccountRows $translate,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return TmdbService::NAME;
    }

    public function description(): string
    {
        return __('import.your_tmdb_watchlist_favorites_ratings_and_lists');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_tmdb');
    }

    public function connectionService(): string
    {
        return TmdbService::CODE;
    }

    public function autoImportMinutes(): int
    {
        return self::AUTO_IMPORT_MINUTES;
    }

    /**
     * @throws TmdbUnavailable|TmdbRejected|ServiceBusy|GrantUnavailable
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return;
        }

        foreach (self::LISTINGS as [$listing, $kind]) {
            foreach ($this->results($connection, $listing->path($kind)) as $item) {
                $row = $this->translate->title($kind, $listing, $item);

                if ($row !== null) {
                    yield $row;
                }
            }
        }

        foreach ($this->results($connection, TmdbListing::Rated->path(MediaKind::Episode)) as $item) {
            $row = $this->translate->episode($item);

            if ($row !== null) {
                yield $row;
            }
        }

        foreach ($this->results($connection, self::LISTS) as $list) {
            yield from $this->list($list);
        }
    }

    /**
     * @param  array<array-key, mixed>  $list
     * @return Generator<int, ListRow|SkipRow|MediaRow>
     */
    private function list(array $list): Generator
    {
        $id = Payload::id($list['id'] ?? null);
        $name = Payload::string($list, 'name');

        if ($id === null || $name === null) {
            return;
        }

        $ref = new ImportListRef($name, description: Payload::string($list, 'description'));
        $rows = [];

        try {
            foreach ($this->client->listPages($id) as $page) {
                foreach (Payload::list($page['items'] ?? null) as $item) {
                    $rows[] = $this->translate->listed($ref, $item);
                }
            }
        } catch (TmdbListPrivate) {
            yield new SkipRow('import.skip_tmdb_list_not_shown', ['label' => $name]);

            return;
        } catch (TmdbNotFound) {
            yield new SkipRow('import.skip_tmdb_list_gone', ['label' => $name]);

            return;
        }

        yield new ListRow($ref);
        yield from array_filter($rows);
    }

    /**
     * Every entry of an account listing, page after page.
     *
     * @return Generator<int, array<array-key, mixed>>
     */
    private function results(ServiceConnection $connection, string $listing): Generator
    {
        foreach ($this->client->accountPages($connection, $listing) as $page) {
            yield from Payload::list($page['results'] ?? null);
        }
    }
}
