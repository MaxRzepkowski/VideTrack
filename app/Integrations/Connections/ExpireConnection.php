<?php

namespace App\Integrations\Connections;

use App\Models\ServiceConnection;

/**
 * Marks a connection the service let go of: runs stop until the user
 * connects again, and the card says why.
 */
final readonly class ExpireConnection
{
    public function execute(ServiceConnection $connection, string $reason): void
    {
        $connection->update(['status' => ConnectionStatus::Expired, 'last_error' => $reason]);
    }
}
