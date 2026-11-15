<?php

namespace App\Integrations\Connections\Data;

/**
 * The start of a device flow. The device code is the secret the instance
 * polls with — it stays on the server; the page only ever sees the user
 * code and the address.
 */
final readonly class DeviceAuthorization
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public ?string $verificationUriComplete,
        public int $expiresIn,
        public int $interval,
    ) {}
}
