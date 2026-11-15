<?php

namespace App\Integrations\Connections;

use App\Models\ServiceConnection;

/**
 * Where a connection's OAuth tokens live between requests. A refresh by one
 * worker invalidates every other copy of the token, so a client reads the
 * stored one again before deciding to refresh, and saves what it got.
 */
final class TokenStore
{
    /** The access token as stored right now, not as this copy of the row remembers it. */
    public function storedAccessToken(ServiceConnection $connection): string
    {
        $connection->refresh();

        return (string) $connection->credential('access_token');
    }

    /**
     * @param  array<string, string|int>  $tokens
     */
    public function save(ServiceConnection $connection, array $tokens): void
    {
        $connection->update(['credentials' => [...$connection->credentials, ...$tokens]]);
    }
}
