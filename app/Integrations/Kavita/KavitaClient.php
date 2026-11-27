<?php

namespace App\Integrations\Kavita;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\Contracts\NamesKeyOwner;
use App\Integrations\Connections\Data\PinnedAddress;
use App\Integrations\Connections\OwnServerApi;
use App\Integrations\Connections\OwnServerNotFound;
use App\Integrations\Connections\OwnServerRejected;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Models\ServiceConnection;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Generator;
use Illuminate\Http\Client\PendingRequest;

/**
 * Kavita's API as its own schema describes it (Kareadita/Kavita@7f97cdc,
 * openapi.json, v0.9.1.24): `{address}/api/…`, an auth key the user makes under
 * User Settings → Manage Auth Keys, sent as `x-api-key`. `GET /api/Account`
 * is the user the key is; `GET /api/Library/libraries` the libraries with
 * their type; `POST /api/Series/all-v2` the series a page at a time, each
 * with the user's pages read, last read and rating, filtered the way Kavita
 * filters its own lists (its CleanupService): read progress in percent, and
 * publication statuses as a list. The way there is `OwnServerClient`'s.
 */
class KavitaClient extends OwnServerApi implements NamesKeyOwner
{
    public const NAME = 'Kavita';

    public const PAGE_SIZE = 100;

    private const BASE = '/api';

    /** Kavita's SeriesFilterField and FilterComparison values the filters below use. */
    private const FIELD_PUBLICATION_STATUS = 2;

    private const FIELD_READ_PROGRESS = 20;

    private const COMPARE_GREATER_THAN = 1;

    private const COMPARE_CONTAINS = 5;

    private const COMBINE_AND = 1;

    /** Kavita's SeriesSortField: by sort name, so a page holds still while the run reads. */
    private const SORT_BY_NAME = 1;

    public function name(): string
    {
        return self::NAME;
    }

    protected function apiPathPattern(): string
    {
        return self::BASE;
    }

    public function owner(PinnedAddress $address, string $key): ?string
    {
        return Payload::string($this->server->check($this->http($address, $key), self::NAME, '/Account'), 'username');
    }

    /**
     * The libraries the user may see, each with its type.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ServiceBusy|ConnectionExpired
     */
    public function libraries(ServiceConnection $connection, PinnedAddress $address): array
    {
        return Payload::list($this->server->read($this->request($connection, $address), self::NAME, '/Library/libraries'));
    }

    /**
     * Every series the user has begun, a page at a time.
     *
     * @return Generator<int, list<array<array-key, mixed>>>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ServiceBusy|ConnectionExpired
     */
    public function begunSeries(ServiceConnection $connection, PinnedAddress $address): Generator
    {
        return $this->seriesPages($connection, $address, [
            ['field' => self::FIELD_READ_PROGRESS, 'comparison' => self::COMPARE_GREATER_THAN, 'value' => '0'],
        ]);
    }

    /**
     * The ids of the series the user has begun whose publication is over,
     * one filtered list instead of a question per series.
     *
     * @return array<int, true>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ServiceBusy|ConnectionExpired
     */
    public function begunAndOver(ServiceConnection $connection, PinnedAddress $address): array
    {
        $ids = [];
        $statements = [
            ['field' => self::FIELD_READ_PROGRESS, 'comparison' => self::COMPARE_GREATER_THAN, 'value' => '0'],
            ['field' => self::FIELD_PUBLICATION_STATUS, 'comparison' => self::COMPARE_CONTAINS, 'value' => KavitaPublicationStatus::overList()],
        ];

        foreach ($this->seriesPages($connection, $address, $statements) as $page) {
            foreach ($page as $series) {
                $id = Payload::positiveInt($series, 'id');

                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * The series a filter keeps, a page at a time; a page that comes back
     * short is the last.
     *
     * @param  list<array{field: int, comparison: int, value: string}>  $statements
     * @return Generator<int, list<array<array-key, mixed>>>
     */
    private function seriesPages(ServiceConnection $connection, PinnedAddress $address, array $statements): Generator
    {
        $page = 1;

        do {
            $series = Payload::list($this->server->search(
                $this->request($connection, $address),
                self::NAME,
                '/Series/all-v2',
                ['statements' => $statements, 'combination' => self::COMBINE_AND, 'sortOptions' => ['sortField' => self::SORT_BY_NAME, 'isAscending' => true]],
                ['PageNumber' => $page++, 'PageSize' => self::PAGE_SIZE],
            ));

            yield $series;
        } while (count($series) === self::PAGE_SIZE);
    }

    private function request(ServiceConnection $connection, PinnedAddress $address): PendingRequest
    {
        return $this->http($address, $this->key($connection));
    }

    private function http(PinnedAddress $address, string $key): PendingRequest
    {
        return $this->server->to($address, self::BASE)->withHeader('x-api-key', $key);
    }
}
