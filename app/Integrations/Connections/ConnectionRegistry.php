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
    /**
     * The service of that code, when it connects the way $capability says;
     * null for one that does not, or no such service.
     *
     * @template T of ConnectableService
     *
     * @param  class-string<T>  $capability
     * @return T|null
     */
    public function capable(string $service, string $capability): ?ConnectableService
    {
        $connectable = $this->find($service);

        return $connectable instanceof $capability ? $connectable : null;
    }

    protected function kind(): string
    {
        return 'connectable service';
    }
}
