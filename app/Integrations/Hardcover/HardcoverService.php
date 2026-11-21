<?php

namespace App\Integrations\Hardcover;

use App\Catalog\Providers\Hardcover\HardcoverClient;
use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;

/**
 * Connecting a Hardcover account with an API key its reader makes on
 * hardcover.app for this, with the scopes to read the profile and the
 * library: nothing for the operator to register, and the key is the
 * account, so there is nobody to pick.
 */
class HardcoverService implements AuthorizesByForm
{
    public const CODE = 'hardcover';

    public const NAME = 'Hardcover';

    public function __construct(private readonly HardcoverLibraryClient $client) {}

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
            new CredentialField('key', __('import.hardcover_api_key'), CredentialFieldType::Password, __('import.make_a_hardcover_key_with_the_link')),
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
        return HardcoverClient::bareKey($values['key']);
    }
}
