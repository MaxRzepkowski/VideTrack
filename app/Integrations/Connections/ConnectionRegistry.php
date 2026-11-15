<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\ConnectableService;
use App\Support\ServiceRegistry;

/**
 * Services a user connects so imports read from them without a file, each
 * behind the configuration it needs.
 *
 * @extends ServiceRegistry<ConnectableService>
 */
final class ConnectionRegistry extends ServiceRegistry
{
    protected function kind(): string
    {
        return 'connectable service';
    }
}
