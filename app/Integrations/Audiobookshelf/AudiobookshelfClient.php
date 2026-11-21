<?php

namespace App\Integrations\Audiobookshelf;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\PinnedAddress;
use App\Integrations\Connections\OwnServerClient;
use App\Integrations\Connections\OwnServerNotFound;
use App\Integrations\Connections\OwnServerRejected;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Models\ServiceConnection;
use Illuminate\Http\Client\PendingRequest;

/**
 * Audiobookshelf's API as its source defines it (advplyr/audiobookshelf@5de038d,
 * server/routers/ApiRouter.js): `{address}/api/…`, `Authorization: Bearer`
 * with an API key an administrator made for the user (Settings → API Keys)
 * or the user's own token. `GET /api/me` is the user with every media
 * progress; `GET /api/items/{id}` the library item one points at. The way
 * there is `OwnServerClient`'s.
 */
class AudiobookshelfClient
{
    public const NAME = 'Audiobookshelf';

    public function __construct(private readonly OwnServerClient $server) {}

    /**
     * The address as typed, without a trailing slash or the API's path.
     * Whether it is a web address at all is `UserAddresses`' to say.
     */
    public function normalizeAddress(string $address): string
    {
        return (string) preg_replace('#/api$#', '', rtrim(trim($address), '/'));
    }

    /**
     * Whose key it is, asked once while connecting.
     *
     * @return array<array-key, mixed>
     *
     * @throws ConnectionRejected
     */
    public function owner(PinnedAddress $address, string $key): array
    {
        return $this->server->check($this->http($address, $key), self::NAME, '/me');
    }

    /**
     * The connection's address, checked again for this run.
     *
     * @throws ConnectionExpired|OwnServerUnavailable
     */
    public function address(ServiceConnection $connection): PinnedAddress
    {
        return $this->server->address($connection, self::NAME);
    }

    /**
     * The user, with every progress Audiobookshelf keeps for them.
     *
     * @return array<array-key, mixed>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ConnectionExpired
     */
    public function me(ServiceConnection $connection, PinnedAddress $address): array
    {
        return $this->server->read($this->http($address, (string) $connection->credential('key')), self::NAME, '/me');
    }

    /**
     * A library item; null once it is gone from the server or the user may
     * no longer open it (a library taken away, an explicit or tagged item
     * held back), while their progress on it stays.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws OwnServerUnavailable|OwnServerRejected|ConnectionExpired
     */
    public function item(ServiceConnection $connection, PinnedAddress $address, string $id): ?array
    {
        return $this->server->find($this->http($address, (string) $connection->credential('key')), self::NAME, '/items/'.rawurlencode($id));
    }

    private function http(PinnedAddress $address, string $key): PendingRequest
    {
        return $this->server->to($address, '/api')->withToken($key);
    }
}
