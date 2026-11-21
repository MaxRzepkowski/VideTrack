<?php

namespace App\Integrations\Tracearr;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\PinnedAddress;
use App\Integrations\Connections\OwnServerApi;
use App\Integrations\Connections\OwnServerNotFound;
use App\Integrations\Connections\OwnServerRejected;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Models\ServiceConnection;
use App\Support\Payload;
use Illuminate\Http\Client\PendingRequest;

/**
 * Tracearr's public API v2, as its source defines it
 * (connorgallopo/Tracearr@ef0fb45, apps/server/src/routes/publicV2):
 * `{address}/api/v2/public/…`, `Authorization: Bearer trr_pub_…`, a key
 * that must belong to the instance owner, 240 requests a minute per key by
 * default, cursor pages of at most 100. The way there is `OwnServerClient`'s.
 */
class TracearrClient extends OwnServerApi
{
    public const KEY_PREFIX = 'trr_pub_';

    private const NAME = 'Tracearr';

    private const BASE = '/api/v2/public';

    private const PAGE_SIZE = 100;

    public function name(): string
    {
        return self::NAME;
    }

    protected function apiPathPattern(): string
    {
        return '/api(/v\\d+(/public)?)?';
    }

    /**
     * The people Tracearr watches, to pick the user from. One request per
     * page; the first answer decides whether the key is any good.
     *
     * @return list<array{id: string, username: string}>
     *
     * @throws ConnectionRejected
     */
    public function users(PinnedAddress $address, string $key): array
    {
        if (! str_starts_with($key, self::KEY_PREFIX)) {
            throw new ConnectionRejected('import.a_tracearr_api_key_starts_with');
        }

        $users = [];
        $cursor = null;

        do {
            $body = $this->server->check($this->http($address, $key), self::NAME, '/users', array_filter([
                'pageSize' => self::PAGE_SIZE,
                'cursor' => $cursor,
            ]), [403 => 'import.this_api_key_does_not_belong']);

            if (! is_array($body['data'] ?? null)) {
                throw new ConnectionRejected('import.service_could_not_be_read_at', ['service' => self::NAME]);
            }

            foreach (Payload::list($body['data']) as $user) {
                $id = Payload::string($user, 'id');

                if ($id !== null) {
                    $users[] = ['id' => $id, 'username' => Payload::string($user, 'username') ?? $id];
                }
            }

            $cursor = Payload::string(Payload::map($body['meta'] ?? null), 'nextCursor');
        } while ($cursor !== null);

        return $users;
    }

    /**
     * One page of a list endpoint, with the connection's key.
     *
     * @param  array<string, string|int>  $query
     * @return array{data: list<array<array-key, mixed>>, nextCursor: string|null}
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ConnectionExpired
     */
    public function page(ServiceConnection $connection, PinnedAddress $address, string $path, array $query): array
    {
        $body = $this->get($connection, $address, $path, [...$query, 'pageSize' => self::PAGE_SIZE]);

        return [
            'data' => Payload::list($body['data'] ?? null),
            'nextCursor' => Payload::string(Payload::map($body['meta'] ?? null), 'nextCursor'),
        ];
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<mixed>|null null when Tracearr does not know the thing
     *
     * @throws OwnServerUnavailable|OwnServerRejected|ConnectionExpired
     */
    public function find(ServiceConnection $connection, PinnedAddress $address, string $path, array $query = []): ?array
    {
        try {
            return $this->get($connection, $address, $path, $query);
        } catch (OwnServerNotFound) {
            return null;
        }
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<mixed>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ConnectionExpired
     */
    private function get(ServiceConnection $connection, PinnedAddress $address, string $path, array $query): array
    {
        return $this->server->read($this->http($address, $this->key($connection)), self::NAME, $path, $query);
    }

    private function http(PinnedAddress $address, string $key): PendingRequest
    {
        return $this->server->to($address, self::BASE)->withToken($key);
    }
}
