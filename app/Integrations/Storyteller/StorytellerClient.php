<?php

namespace App\Integrations\Storyteller;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\PinnedAddress;
use App\Integrations\Connections\OwnServerClient;
use App\Integrations\Connections\OwnServerNotFound;
use App\Integrations\Connections\OwnServerRejected;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Integrations\Connections\UnusableAddress;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Support\Payload;
use Illuminate\Http\Client\PendingRequest;
use LogicException;

/**
 * Storyteller's API as its source defines it (storyteller-platform/storyteller@0d6147a,
 * applications/web/src/app/api/v2): `{address}/api/v2/…`, `Authorization:
 * Bearer st_…` with an API token the user makes under Preferences → Devices
 * & tokens. Listing the books takes a token with full access: one for
 * reading only lacks the permission to list them, and `/user` answers it
 * 403. `GET /api/v2/books` is every book with the user's own status and
 * position, in one answer. The way there is `OwnServerClient`'s.
 */
class StorytellerClient
{
    public const NAME = 'Storyteller';

    public const TOKEN_PREFIX = 'st_';

    private const BASE = '/api/v2';

    /** The API's path a user may paste with the address, as a pattern. */
    private const API_PATH_PATTERN = '/api(/v\\d+)?';

    public function __construct(private readonly OwnServerClient $server) {}

    /**
     * The address the user typed, at its base and checked for the owner.
     *
     * @throws UnusableAddress
     */
    public function pin(string $typed, User $owner): PinnedAddress
    {
        return $this->server->pinTyped($typed, self::API_PATH_PATTERN, $owner);
    }

    /**
     * Whose token it is, asked once each time the form is checked; a token
     * that cannot list the books is turned down here, not on the first run.
     *
     * @return string|null the username, when Storyteller gives one
     *
     * @throws ConnectionRejected
     */
    public function owner(PinnedAddress $address, string $token): ?string
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX)) {
            throw new ConnectionRejected('import.a_storyteller_token_starts_with');
        }

        $user = $this->server->check($this->http($address, $token), self::NAME, '/user', refusals: [403 => 'import.this_storyteller_token_has_no_full_access']);

        if (Payload::bool(Payload::map($user['permissions'] ?? null), 'bookList') !== true) {
            throw new ConnectionRejected('import.your_storyteller_user_may_not_list');
        }

        return Payload::string($user, 'username');
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
     * Every book of the library, each with the user's status and position.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ConnectionExpired
     */
    public function books(ServiceConnection $connection, PinnedAddress $address): array
    {
        $token = $connection->credential('key') ?? throw new LogicException('A Storyteller connection without its token.');

        return Payload::list($this->server->read($this->http($address, $token), self::NAME, '/books'));
    }

    private function http(PinnedAddress $address, string $token): PendingRequest
    {
        return $this->server->to($address, self::BASE)->withToken($token);
    }
}
