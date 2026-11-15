<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Data\PinnedAddress;
use App\Models\User;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether the server may call an address a user typed into a connect form.
 * A public address, yes. One in a private network (the server itself, the
 * local network, another container, a cloud's metadata endpoint) only for an
 * administrator, or when the operator listed its host: an ordinary account
 * must not turn the form into a way into the network the server stands in.
 */
final readonly class UserAddresses
{
    public function __construct(private HostResolver $resolver) {}

    /** Ranges a name may resolve to that PHP's global-range check lets through. */
    private const NOT_PUBLIC = ['100.64.0.0/10', '224.0.0.0/4', 'ff00::/8'];

    /**
     * @throws AddressRefused|AddressNotFound
     */
    public function pin(string $url, User $owner): PinnedAddress
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new AddressRefused('import.this_is_not_a_web_address');
        }

        $host = strtolower(trim($host, '[]'));
        $ips = $this->resolve($host);

        if ($ips === []) {
            throw new AddressNotFound('import.this_address_could_not_be_found');
        }

        $mayReachPrivate = $owner->isAdmin() || in_array($host, $this->operatorHosts(), true);

        if (! $mayReachPrivate && array_filter($ips, fn (string $ip): bool => ! self::isPublic($ip)) !== []) {
            throw new AddressRefused('import.this_address_is_in_a_private_network');
        }

        $port = parse_url($url, PHP_URL_PORT);

        return new PinnedAddress($url, $host, is_int($port) ? $port : ($scheme === 'https' ? 443 : 80), $ips[0]);
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            && ! IpUtils::checkIp($ip, [...IpUtils::PRIVATE_SUBNETS, ...self::NOT_PUBLIC]);
    }

    /**
     * Every address the host names: a literal IP is itself. A name counts
     * only its IPv4 addresses: the request is pinned to the one checked, so
     * an IPv6 record is never what it reaches (an IPv6 service is typed as a
     * literal).
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->ipv4($host);
    }

    /** @return list<string> */
    private function operatorHosts(): array
    {
        return array_values(array_filter(array_map(
            fn (string $host): string => strtolower(trim($host)),
            explode(',', config()->string('tracker.integrations.private_hosts')),
        )));
    }
}
