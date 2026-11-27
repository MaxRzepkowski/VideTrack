<?php

namespace App\Integrations\Storyteller;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
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
 * Storyteller's API as its source defines it (storyteller-platform/storyteller@0d6147a,
 * applications/web/src/app/api/v2): `{address}/api/v2/…`, `Authorization:
 * Bearer st_…` with an API token the user makes under Preferences → Devices
 * & tokens. Listing the books takes a token with full access: one for
 * reading only lacks the permission to list them, and `/user` answers it
 * 403. `GET /api/v2/books` is every book with the user's own status and
 * position, in one answer. The way there is `OwnServerClient`'s.
 */
class StorytellerClient extends OwnServerApi implements NamesKeyOwner
{
    public const NAME = 'Storyteller';

    public const TOKEN_PREFIX = 'st_';

    private const BASE = '/api/v2';

    public function name(): string
    {
        return self::NAME;
    }

    protected function apiPathPattern(): string
    {
        return '/api(/v\\d+)?';
    }

    /**
     * Whose token it is, asked once each time the form is checked; a token
     * that cannot list the books is turned down here, not on the first run.
     *
     * @return string|null the username, when Storyteller gives one
     *
     * @throws ConnectionRejected
     */
    public function owner(PinnedAddress $address, string $key): ?string
    {
        if (! str_starts_with($key, self::TOKEN_PREFIX)) {
            throw new ConnectionRejected('import.a_storyteller_token_starts_with');
        }

        $user = $this->server->check($this->http($address, $key), self::NAME, '/user', refusals: [403 => 'import.this_storyteller_token_has_no_full_access']);

        if (Payload::bool(Payload::map($user['permissions'] ?? null), 'bookList') !== true) {
            throw new ConnectionRejected('import.your_storyteller_user_may_not_list');
        }

        return Payload::string($user, 'username');
    }

    /**
     * Every book of the library, each with the user's status and position.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ServiceBusy|ConnectionExpired
     */
    public function books(ServiceConnection $connection, PinnedAddress $address): array
    {
        return Payload::list($this->server->read($this->http($address, $this->key($connection)), self::NAME, '/books'));
    }

    private function http(PinnedAddress $address, string $token): PendingRequest
    {
        return $this->server->to($address, self::BASE)->withToken($token);
    }
}
