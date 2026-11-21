<?php

namespace App\Integrations\Connections\Contracts;

use App\Support\Forms\CredentialField;

/**
 * A service whose card has fields to type before connecting: an address
 * and a key to paste (AuthorizesByForm), or what a device code is asked
 * with (AuthorizesByDevice). The values are validated as one form, every
 * missing one named at once.
 */
interface HasConnectionFields extends ConnectableService
{
    /**
     * The card's fields; none for a service that asks for nothing.
     *
     * @return list<CredentialField>
     */
    public function connectionFields(): array;
}
