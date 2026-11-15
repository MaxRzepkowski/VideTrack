<?php

namespace App\Integrations\Connections;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Whether a connection can still read. Expired means the service stopped
 * accepting what we hold (revoked, refresh window lapsed) and only the user
 * connecting again can fix it — runs stop there instead of retrying.
 */
#[TypeScript]
enum ConnectionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
}
