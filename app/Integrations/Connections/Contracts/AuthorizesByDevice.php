<?php

namespace App\Integrations\Connections\Contracts;

use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Integrations\Connections\DevicePoll;

/**
 * RFC 8628 device flow: the service hands out a short code, the user
 * approves it on the service's own site, and the instance polls until the
 * approval lands. Needs no callback address, so it works on an instance
 * that only answers on localhost.
 */
interface AuthorizesByDevice extends ConnectableService
{
    public function beginDeviceAuthorization(): DeviceAuthorization;

    /**
     * One poll. A grant comes back only on approval; everything else is a
     * DevicePoll state the caller acts on.
     */
    public function pollDeviceAuthorization(string $deviceCode): DevicePoll|ConnectionGrant;
}
