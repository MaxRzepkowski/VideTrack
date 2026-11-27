<?php

use App\Support\LoopbackAddress;
use Tests\TestCase;

uses(TestCase::class);

test('an address naming the computer it is read on is a loopback one', function (string $url, bool $loopback) {
    expect(LoopbackAddress::is($url))->toBe($loopback);
})->with([
    'localhost' => ['http://localhost:8080', true],
    'the loopback address' => ['http://127.0.0.1/', true],
    'the IPv6 loopback, in brackets' => ['http://[::1]:8080', true],
    "the instance's own service name" => ['http://app:8080/webhooks/plex', true],
    'a network address' => ['http://192.168.1.50:8080', false],
    'a name' => ['https://tracker.example.com', false],
    'upper case' => ['http://LOCALHOST', true],
]);
