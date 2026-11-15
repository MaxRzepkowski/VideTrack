<?php

namespace App\Integrations\Connections;

/**
 * A host name's IPv4 addresses through the system resolver: hosts file and
 * container names included, as the request itself would see them.
 */
class HostResolver
{
    /** @return list<string> */
    public function ipv4(string $host): array
    {
        return array_values(array_unique(gethostbynamel($host) ?: []));
    }
}
