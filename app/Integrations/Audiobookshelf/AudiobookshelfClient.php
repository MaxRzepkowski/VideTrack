<?php

namespace App\Integrations\Audiobookshelf;

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
use Illuminate\Http\Client\PendingRequest;

/**
 * Audiobookshelf's API as its source defines it (advplyr/audiobookshelf@5de038d,
 * server/routers/ApiRouter.js): `{address}/api/…`, `Authorization: Bearer`
 * with an API key an administrator made for the user (Settings → API Keys)
 * or the user's own token. `GET /api/me` is the user with every media
 * progress; `GET /api/items/{id}` the library item one points at. The way
 * there is `OwnServerClient`'s.
 */
class AudiobookshelfClient extends OwnServerApi implements NamesKeyOwner
{
    private const BASE = '/api';

    public const NAME = 'Audiobookshelf';

    public function name(): string
    {
        return self::NAME;
    }

    protected function apiPathPattern(): string
    {
        return '/api';
    }

    public function owner(PinnedAddress $address, string $key): ?string
    {
        return Payload::string($this->server->check($this->http($address, $key), self::NAME, '/me'), 'username');
    }

    /**
     * The user, with every progress Audiobookshelf keeps for them.
     *
     * @return array<array-key, mixed>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ServiceBusy|ConnectionExpired
     */
    public function me(ServiceConnection $connection, PinnedAddress $address): array
    {
        return $this->server->read($this->http($address, $this->key($connection)), self::NAME, '/me');
    }

    /**
     * A library item; null once it is gone from the server or the user may
     * no longer open it (a library taken away, an explicit or tagged item
     * held back), while their progress on it stays.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws OwnServerUnavailable|OwnServerRejected|ServiceBusy|ConnectionExpired
     */
    public function item(ServiceConnection $connection, PinnedAddress $address, string $id): ?array
    {
        return $this->server->find($this->http($address, $this->key($connection)), self::NAME, '/items/'.rawurlencode($id));
    }

    private function http(PinnedAddress $address, string $key): PendingRequest
    {
        return $this->server->to($address, self::BASE)->withToken($key);
    }
}
