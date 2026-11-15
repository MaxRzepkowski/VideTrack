<?php

namespace App\Integrations\Connections\Contracts;

use App\Models\ServiceConnection;

/**
 * A service that can be told we are done with a grant. Disconnecting calls
 * it before the row goes; a failure there never keeps the row alive.
 */
interface RevokesAccess extends ConnectableService
{
    public function revoke(ServiceConnection $connection): void;
}
