<?php

namespace App\Integrations\Connections;

/**
 * The `error` of an OAuth answer that changes what a device flow does next
 * (RFC 6749 5.2, RFC 8628 3.5). Any other code ends the attempt.
 */
enum OAuthError: string
{
    case AuthorizationPending = 'authorization_pending';
    case SlowDown = 'slow_down';
    case InvalidClient = 'invalid_client';
    case UnauthorizedClient = 'unauthorized_client';
}
