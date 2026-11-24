<?php

use App\Integrations\Connections\UserAddresses;

test('an address leads to the server when it is its own, or in a network it stands in', function (string $ip, bool $server) {
    expect(UserAddresses::leadsToTheServer($ip, ['172.18.0.2/16', 'fd12:3456::2/64']))->toBe($server);
})->with([
    'loopback' => ['127.0.0.1', true],
    'loopback, further in' => ['127.1.2.3', true],
    'this host' => ['0.0.0.0', true],
    'a cloud metadata endpoint' => ['169.254.169.254', true],
    'multicast' => ['224.0.0.1', true],
    'broadcast' => ['255.255.255.255', true],
    'a container beside it' => ['172.18.0.5', true],
    'IPv6 loopback' => ['::1', true],
    'IPv6 link-local' => ['fe80::1', true],
    'IPv4 loopback written as IPv6' => ['::ffff:127.0.0.1', true],
    'a container beside it, over IPv6' => ['fd12:3456::9', true],
    'the home network' => ['192.168.1.20', false],
    'another private network' => ['10.1.2.3', false],
    'another Docker network' => ['172.19.0.5', false],
    'a VPN of the home' => ['100.100.1.1', false],
    'a home network over IPv6' => ['fd00::1', false],
    'the internet' => ['93.184.215.14', false],
    'the internet over IPv6' => ['2606:4700:4700::1111', false],
]);
