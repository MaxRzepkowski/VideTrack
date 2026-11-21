<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\AuthorizesByDevice;
use App\Integrations\Connections\Contracts\RevokesAccess;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Models\ServiceConnection;
use LogicException;

/**
 * A service connected through its OAuth device flow: the user approves a
 * code on the service's own site, and the instance never sees a password
 * nor needs a callback address. What differs between services is where the
 * grant lives, what the card asks for first and how the account is named;
 * the rest is DeviceGrantClient's and TokenGrantClient's. What was typed is
 * kept with the tokens, for every later call and refresh.
 */
abstract class DeviceGrantService implements AuthorizesByDevice, RevokesAccess
{
    public function __construct(
        private readonly DeviceGrantClient $grants,
        private readonly TokenGrantClient $tokens,
    ) {}

    /**
     * Where the service keeps its grant, for what the user typed.
     *
     * @param  array<string, string>  $values
     */
    abstract protected function grant(array $values): DeviceGrant;

    /**
     * The account's name for the card, read with a token straight from the
     * device flow; null when it cannot be read, which never loses the grant.
     *
     * @param  array<string, string>  $values
     */
    abstract protected function accountName(string $accessToken, array $values): ?string;

    public function beginDeviceAuthorization(array $values): DeviceAuthorization
    {
        return $this->grants->begin($this->grant($values));
    }

    public function pollDeviceAuthorization(string $deviceCode, array $values): DevicePoll|ConnectionGrant
    {
        $result = $this->grants->poll($this->grant($values), $deviceCode);

        return $result instanceof DevicePoll ? $result : new ConnectionGrant([...$values, ...$result], $this->accountName($result['access_token'], $values));
    }

    /** The grant is revoked with what the connection was made with, kept beside its tokens. */
    public function revoke(ServiceConnection $connection): void
    {
        $values = [];

        foreach ($this->connectionFields() as $field) {
            $values[$field->key] = $connection->credential($field->key) ?? throw new LogicException("A connection without the {$field->key} it was made with.");
        }

        $this->tokens->revoke($this->grant($values)->tokenGrant(), $connection);
    }
}
