<?php

namespace App\Integrations\Nuvio;

use App\Integrations\Connections\Data\PinnedAddress;

/**
 * The Nuvio server a connection talks to: Nuvio's cloud or one the user
 * runs, its publishable key, and the IP its address was approved at.
 */
final readonly class NuvioServer
{
    public function __construct(
        public string $url,
        public string $key,
        public PinnedAddress $address,
    ) {}

    public function isCloud(): bool
    {
        return $this->url === NuvioClient::CLOUD_URL;
    }
}
