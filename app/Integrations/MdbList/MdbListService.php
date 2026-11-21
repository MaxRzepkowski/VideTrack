<?php

namespace App\Integrations\MdbList;

use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;

/**
 * Connecting an MDBList account with the API key from its preferences:
 * nothing for the operator to register, and the key is the account, so
 * there is nobody to pick.
 */
class MdbListService implements AuthorizesByForm
{
    public const CODE = 'mdblist';

    public const NAME = 'MDBList';

    public function __construct(private readonly MdbListClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function connectionFields(): array
    {
        return [
            new CredentialField('key', __('import.mdblist_api_key'), CredentialFieldType::Password, __('import.the_key_from_your_mdblist_preferences_linked')),
        ];
    }

    public function verify(User $owner, array $values): array
    {
        $this->client->accept($this->key($values));

        return [];
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        $key = $this->key($values);

        return new ConnectionGrant(credentials: ['key' => $key], accountLabel: $this->client->accept($key));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function key(array $values): string
    {
        return trim($values['key']);
    }
}
