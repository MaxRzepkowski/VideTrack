<?php

use App\Integrations\Connections\UserAddresses;

test('only an address on the public internet counts as public', function (string $ip, bool $public) {
    expect(UserAddresses::isPublic($ip))->toBe($public);
})->with([
    ['127.0.0.1', false],
    ['10.1.2.3', false],
    ['172.16.0.9', false],
    ['192.168.1.20', false],
    ['169.254.169.254', false],
    ['100.100.1.1', false],
    ['0.0.0.0', false],
    ['224.0.0.1', false],
    ['::1', false],
    ['fd00::1', false],
    ['fe80::1', false],
    ['::ffff:127.0.0.1', false],
    ['93.184.215.14', true],
    ['2606:4700:4700::1111', true],
]);
