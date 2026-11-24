<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Data\PinnedAddress;
use App\Models\User;
use LogicException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether the server may call an address a user gives: a connect form's, a
 * notification channel's, a webhook's, a browser's push service. Anything on
 * the internet or in the home network, yes: that is where a Home Assistant,
 * a Kavita or an n8n runs. One that leads to the server itself (its loopback,
 * the containers beside it, a cloud's metadata endpoint) only for an
 * administrator, or when the operator listed its host: an ordinary account
 * must not turn the instance against the machine it runs on.
 */
final readonly class UserAddresses
{
    /**
     * From the server these always lead to itself or nowhere: "this host",
     * loopback, link-local (a cloud's metadata endpoint), multicast and the
     * reserved rest; for IPv6 also the old IPv4-compatible block.
     */
    private const THE_SERVERS = [
        '0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4',
        '::/96', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(
        private HostResolver $resolver,
        private ServerNetworks $networks,
    ) {}

    /**
     * @throws AddressRefused|AddressNotFound
     */
    public function pin(string $url, User $owner): PinnedAddress
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new AddressRefused('common.this_is_not_a_web_address');
        }

        $host = strtolower(trim($host, '[]'));
        $ips = $this->resolve($host);

        if ($ips === []) {
            throw new AddressNotFound('common.this_address_could_not_be_found');
        }

        $mayReachTheServer = $owner->isAdmin() || in_array($host, $this->operatorHosts(), true);

        if (! $mayReachTheServer) {
            $own = $this->networks->ranges();

            if (array_filter($ips, fn (string $ip): bool => self::leadsToTheServer($ip, $own)) !== []) {
                throw new AddressRefused('common.this_address_leads_to_the_server');
            }
        }

        $port = parse_url($url, PHP_URL_PORT);

        return new PinnedAddress($url, $host, is_int($port) ? $port : ($scheme === 'https' ? 443 : 80), $ips[0]);
    }

    /** The validation rule of a form field holding such an address, for $owner. */
    public function rule(User $owner): ReachableAddress
    {
        return new ReachableAddress($this, $owner);
    }

    /**
     * Whether a request to $ip ends at the server or beside it: one of the
     * ranges that always do, or one of the networks it stands in.
     *
     * @param  list<string>  $ownNetworks  CIDR ranges of the server's interfaces
     */
    public static function leadsToTheServer(string $ip, array $ownNetworks): bool
    {
        $ip = self::unmapped($ip);

        return IpUtils::checkIp($ip, [...self::THE_SERVERS, ...$ownNetworks]);
    }

    /** An IPv4 address written as IPv6 (`::ffff:127.0.0.1`) is checked as the IPv4 one it is. */
    private static function unmapped(string $ip): string
    {
        $packed = inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12)) ?: throw new LogicException('Four bytes unpack as an IPv4 address.');
        }

        return $ip;
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
