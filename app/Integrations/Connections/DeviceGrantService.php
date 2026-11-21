<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use App\Integrations\Connections\Contracts\RevokesAccess;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Models\ServiceConnection;

/**
 * A service connected through its OAuth device flow: the user approves a
 * code on the service's own site, and the instance never sees a password
 * nor needs a callback address. What differs between services is where the
 * grant lives and how the account is named; the rest is DeviceGrantClient's.
 */
abstract class DeviceGrantService implements AuthorizesByDevice, RevokesAccess
{
    public function __construct(private readonly DeviceGrantClient $grants) {}

    /** Where the service keeps its grant. */
    abstract protected function grant(): DeviceGrant;

    /**
     * The account's name for the card, read with a token straight from the
     * device flow; null when it cannot be read, which never loses the grant.
     */
    abstract protected function accountName(string $accessToken): ?string;

    public function beginDeviceAuthorization(): DeviceAuthorization
    {
        return $this->grants->begin($this->grant());
    }

    public function pollDeviceAuthorization(string $deviceCode): DevicePoll|ConnectionGrant
    {
        $result = $this->grants->poll($this->grant(), $deviceCode);

        return $result instanceof DevicePoll ? $result : new ConnectionGrant($result, $this->accountName($result['access_token']));
    }

    public function revoke(ServiceConnection $connection): void
    {
        $this->grants->revoke($this->grant(), $connection);
    }
}
