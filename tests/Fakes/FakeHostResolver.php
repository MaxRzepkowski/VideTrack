<?php

namespace Tests\Fakes;

use App\Integrations\Connections\HostResolver;

/** Names a test resolves, without DNS; an unlisted name resolves to nothing. */
class FakeHostResolver extends HostResolver
{
    /** @var array<string, list<string>> */
    public static array $hosts = [
        'localhost' => ['127.0.0.1'],
        'tracearr.lan' => ['192.168.1.20'],
        'abs.lan' => ['192.168.1.21'],
        'storyteller.lan' => ['192.168.1.22'],
        'kavita.lan' => ['192.168.1.23'],
        'tracearr.example.com' => ['93.184.215.14'],
        'rebound.example.com' => ['93.184.215.14', '127.0.0.1'],
        'push.example.com' => ['93.184.215.14'],
        'n8n.example.com' => ['93.184.215.14'],
        // A container beside the server, in its Docker network.
        'apprise' => ['172.18.0.5'],
        'tracearr' => ['172.18.0.6'],
    ];

    public function ipv4(string $host): array
    {
        return self::$hosts[$host] ?? [];
    }
}
