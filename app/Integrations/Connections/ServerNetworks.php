<?php

namespace App\Integrations\Connections;

use App\Support\Payload;
use LogicException;

/**
 * The networks the server's own interfaces stand in, as the system lists
 * them: what lies there is the server itself or a container beside it (the
 * Docker network of the compose file). A container on the host's network has
 * the home network among them.
 */
class ServerNetworks
{
    /** @return list<string> CIDR ranges, the interface's own address with its prefix */
    public function ranges(): array
    {
        return self::of(net_get_interfaces() ?: []);
    }

    /**
     * The ranges of interfaces as `net_get_interfaces()` lists them.
     *
     * @param  array<array-key, mixed>  $interfaces
     * @return list<string>
     */
    public static function of(array $interfaces): array
    {
        $ranges = [];

        foreach (Payload::list($interfaces) as $interface) {
            foreach (Payload::list($interface['unicast'] ?? null) as $address) {
                $ip = Payload::string($address, 'address');
                $mask = Payload::string($address, 'netmask');

                if ($ip !== null && $mask !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ranges[] = $ip.'/'.self::prefix($mask, $ip);
                }
            }
        }

        return $ranges;
    }

    /** "255.255.0.0" is 16, "ffff:ffff:ffff:ffff::" is 64; a mask it cannot read leaves the address alone. */
    private static function prefix(string $mask, string $ip): int
    {
        $packed = inet_pton($mask);

        if ($packed === false) {
            $address = inet_pton($ip) ?: throw new LogicException('A validated IP packs.');

            return strlen($address) * 8;
        }

        $bits = 0;

        foreach (str_split($packed) as $byte) {
            $bits += substr_count(decbin(ord($byte)), '1');
        }

        return $bits;
    }
}
