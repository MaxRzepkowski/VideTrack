<?php

namespace App\Integrations\Simkl;

use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use App\Integrations\Connections\Contracts\RevokesAccess;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Integrations\Connections\DevicePoll;
use App\Models\ServiceConnection;

/**
 * Connecting a Simkl account through AUTH V2's device flow: the user opens
 * simkl.com/pin with the code filled in and approves; the instance never
 * sees a password and needs no callback address. The grant asks for
 * media:read only — nothing is ever written back.
 */
class SimklService implements AuthorizesByDevice, RevokesAccess
{
    public const CODE = 'simkl';

    public function __construct(private readonly SimklClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Simkl';
    }

    public function beginDeviceAuthorization(): DeviceAuthorization
    {
        $device = $this->client->requestDeviceCode();

        return new DeviceAuthorization(
            deviceCode: $device['device_code'],
            userCode: $device['user_code'],
            verificationUri: $device['verification_uri'],
            verificationUriComplete: $device['verification_uri_complete'],
            expiresIn: $device['expires_in'],
            interval: $device['interval'],
        );
    }

    public function pollDeviceAuthorization(string $deviceCode): DevicePoll|ConnectionGrant
    {
        $result = $this->client->pollDeviceCode($deviceCode);

        if ($result instanceof DevicePoll) {
            return $result;
        }

        return new ConnectionGrant($result, $this->client->accountName($result['access_token']));
    }

    public function revoke(ServiceConnection $connection): void
    {
        $this->client->revoke($connection);
    }
}
