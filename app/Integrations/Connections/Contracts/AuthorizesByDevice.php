<?php

namespace App\Integrations\Connections\Contracts;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Integrations\Connections\DevicePoll;
use App\Support\ServiceUnavailable;

/**
 * RFC 8628 device flow: the service hands out a short code, the user
 * approves it on the service's own site, and the instance polls until the
 * approval lands. Needs no callback address, so it works on an instance
 * that only answers on localhost. What the card's fields held when it
 * began rides along with every poll.
 */
interface AuthorizesByDevice extends HasConnectionFields
{
    /**
     * @param  array<string, string>  $values  the connection fields, as typed
     *
     * @throws ConnectionRejected|ServiceUnavailable
     */
    public function beginDeviceAuthorization(array $values): DeviceAuthorization;

    /**
     * One poll. A grant comes back only on approval; everything else is a
     * DevicePoll state the caller acts on.
     *
     * @param  array<string, string>  $values  the connection fields the attempt began with
     */
    public function pollDeviceAuthorization(string $deviceCode, array $values): DevicePoll|ConnectionGrant;
}
