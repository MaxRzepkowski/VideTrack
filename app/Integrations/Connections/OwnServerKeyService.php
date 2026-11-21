<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Contracts\NamesKeyOwner;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;

/**
 * Connecting a server the user runs with its address and a key that is the
 * user: the key names the account, so there is nobody to pick. A service
 * says how its key is called and where it is made, and gives an example
 * address; its client says whose key it is.
 */
abstract class OwnServerKeyService implements AuthorizesByForm
{
    public function __construct(private readonly OwnServerApi&NamesKeyOwner $api) {}

    public function name(): string
    {
        return $this->api->name();
    }

    /** An address the server answers at, with its usual port, for the field's help. */
    abstract protected function exampleAddress(): string;

    /** What the key is called on the card. */
    abstract protected function keyLabel(): string;

    /** Where in the server the user makes the key. */
    abstract protected function keyHelp(): string;

    public function connectionFields(): array
    {
        return [
            new CredentialField('url', __('import.service_address', ['service' => $this->name()]), CredentialFieldType::Url, __('import.the_address_you_open_service_at', ['service' => $this->name(), 'example' => $this->exampleAddress()])),
            new CredentialField('key', $this->keyLabel(), CredentialFieldType::Password, $this->keyHelp()),
        ];
    }

    public function verify(User $owner, array $values): array
    {
        $this->api->owner($this->api->pin($values['url'], $owner), $values['key']);

        return [];
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        $address = $this->api->pin($values['url'], $owner);

        return new ConnectionGrant(credentials: ['url' => $address->url, 'key' => $values['key']], accountLabel: $this->api->owner($address, $values['key']));
    }
}
