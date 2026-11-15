<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\ServiceConnection;
use App\Models\User;

/**
 * Keeps a fresh grant as the user's connection to the service, replacing
 * whatever they had with it. The read position is dropped — it may belong
 * to another account — so the next run reads everything again, which the
 * idempotent import makes harmless. The auto-import choice survives a
 * reconnect.
 */
final readonly class ConnectService
{
    public function execute(User $user, string $service, ConnectionGrant $grant): ServiceConnection
    {
        return ServiceConnection::query()->updateOrCreate(
            ['user_id' => $user->id, 'service' => $service],
            [
                'credentials' => $grant->credentials,
                'settings' => $grant->settings,
                'state' => null,
                'status' => ConnectionStatus::Active,
                'account_label' => $grant->accountLabel,
                'last_error' => null,
            ],
        );
    }
}
