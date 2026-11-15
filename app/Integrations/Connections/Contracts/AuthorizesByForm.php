<?php

namespace App\Integrations\Connections\Contracts;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\ConnectionChoice;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\UnusableAddress;
use App\Integrations\Import\Data\CredentialField;
use App\Models\User;

/**
 * The user pastes what the service gave them (an address and an API key)
 * and, when the service holds several accounts, picks theirs. verify()
 * makes one request with what was typed; a rejection is final — it is
 * never retried, a wrong key is asked about once. An address field is
 * called only once `UserAddresses` allows it for the owner.
 */
interface AuthorizesByForm extends ConnectableService
{
    /**
     * @return list<CredentialField>
     */
    public function connectionFields(): array;

    /**
     * Checks the typed values against the service and lists the accounts to
     * pick from; an empty list means there is nothing to pick.
     *
     * @param  array<string, string>  $values
     * @return list<ConnectionChoice>
     *
     * @throws ConnectionRejected|UnusableAddress
     */
    public function verify(User $owner, array $values): array;

    /**
     * @param  array<string, string>  $values
     *
     * @throws ConnectionRejected|UnusableAddress
     */
    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant;
}
