<?php

namespace Tests\Fakes;

use App\Integrations\Connections\ServerNetworks;

/** The networks a test's server stands in: the Docker network of a compose file. */
class FakeServerNetworks extends ServerNetworks
{
    /** @var list<string> */
    public static array $ranges = ['172.18.0.2/16'];

    public function ranges(): array
    {
        return self::$ranges;
    }
}
