<?php

namespace App\Integrations\Connections\Contracts;

/**
 * An outside service a user can connect so imports read from it without a
 * file. How the user authorizes is a capability on top of this contract:
 * AuthorizesByDevice (a code typed on the service's site) or
 * AuthorizesByForm (an address and key pasted in). Nothing here writes to
 * the service — connections exist to read.
 */
interface ConnectableService
{
    /** Stable code; the service_connections.service column holds it. */
    public function code(): string;

    /** The service's own name ("Simkl"), shown as is. */
    public function name(): string;
}
