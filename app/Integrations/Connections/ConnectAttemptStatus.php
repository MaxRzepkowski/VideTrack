<?php

namespace App\Integrations\Connections;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where an attempt to connect a service stands, as the connect card is
 * told: still waiting for the user, run out, refused, done, or called off.
 */
#[TypeScript]
enum ConnectAttemptStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Failed = 'failed';
    case Connected = 'connected';
    case Cancelled = 'cancelled';
}
