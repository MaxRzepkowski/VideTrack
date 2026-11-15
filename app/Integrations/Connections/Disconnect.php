<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\RevokesAccess;
use App\Models\ServiceConnection;
use App\Support\ExternalServiceException;

/**
 * Ends a connection: tells the service we are done when it listens, then
 * forgets the row. A revoke that fails (service down, grant already gone)
 * never keeps the row — the user asked for it to go.
 */
final readonly class Disconnect
{
    public function __construct(private ConnectionRegistry $registry) {}

    public function execute(ServiceConnection $connection): void
    {
        $connectable = $this->registry->has($connection->service) ? $this->registry->get($connection->service) : null;

        if ($connectable instanceof RevokesAccess) {
            try {
                $connectable->revoke($connection);
            } catch (ExternalServiceException $e) {
                report($e);
            }
        }

        $connection->delete();
    }
}
