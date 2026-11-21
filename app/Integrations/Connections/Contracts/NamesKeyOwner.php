<?php

namespace App\Integrations\Connections\Contracts;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\PinnedAddress;

/**
 * A client contract (an `OwnServerApi` that can say whose key a key is), not
 * a connectable service: the key is the account, so connecting needs nobody
 * picked.
 */
interface NamesKeyOwner
{
    /**
     * Asked once each time the connect form is checked; a key the server
     * does not take is turned down here.
     *
     * @return string|null the account's name, when the server gives one
     *
     * @throws ConnectionRejected
     */
    public function owner(PinnedAddress $address, string $key): ?string;
}
