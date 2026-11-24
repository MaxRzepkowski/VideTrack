<?php

use App\Integrations\Connections\ServerNetworks;

test("the server's own networks are its interfaces' addresses with the prefix of their masks", function () {
    $interfaces = [
        'lo' => ['unicast' => [
            ['flags' => 65609, 'family' => 2, 'address' => '127.0.0.1', 'netmask' => '255.0.0.0'],
            ['flags' => 65609, 'family' => 10, 'address' => '::1', 'netmask' => 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        ], 'up' => true],
        'eth0' => ['unicast' => [
            // The link layer: no address to range.
            ['flags' => 4163, 'family' => 17],
            ['flags' => 4163, 'family' => 2, 'address' => '172.18.0.2', 'netmask' => '255.255.0.0'],
            ['flags' => 4163, 'family' => 10, 'address' => 'fd12:3456::2', 'netmask' => 'ffff:ffff:ffff:ffff::'],
            // A mask it cannot read leaves the address alone.
            ['flags' => 4163, 'family' => 2, 'address' => '10.9.0.4', 'netmask' => 'not a mask'],
        ], 'up' => true],
    ];

    expect(ServerNetworks::of($interfaces))->toBe([
        '127.0.0.1/8',
        '::1/128',
        '172.18.0.2/16',
        'fd12:3456::2/64',
        '10.9.0.4/32',
    ]);
});

test("the server's own networks are read from its interfaces, loopback among them", function () {
    expect((new ServerNetworks)->ranges())->toContain('127.0.0.1/8');
});
