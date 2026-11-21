<?php

namespace App\Integrations\Audiobookshelf;

use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\UserAddresses;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;
use App\Support\Payload;

/**
 * Connecting an Audiobookshelf server: its address and an API key that acts
 * as this user. The key is the user, so there is nobody to pick.
 */
class AudiobookshelfService implements AuthorizesByForm
{
    public const CODE = 'audiobookshelf';

    public function __construct(
        private readonly AudiobookshelfClient $client,
        private readonly UserAddresses $addresses,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return AudiobookshelfClient::NAME;
    }

    public function connectionFields(): array
    {
        return [
            new CredentialField('url', __('import.service_address', ['service' => $this->name()]), CredentialFieldType::Url, __('import.the_address_you_open_service_at', ['service' => $this->name(), 'example' => 'http://192.168.1.10:13378'])),
            new CredentialField('key', __('import.api_key'), CredentialFieldType::Password, __('import.in_audiobookshelf_settings_api_keys')),
        ];
    }

    public function verify(User $owner, array $values): array
    {
        $this->owner($owner, $values);

        return [];
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        [$url, $me] = $this->owner($owner, $values);

        return new ConnectionGrant(
            credentials: ['url' => $url, 'key' => trim($values['key'])],
            accountLabel: Payload::string($me, 'username'),
        );
    }

    /**
     * @param  array<string, string>  $values
     * @return array{string, array<array-key, mixed>} the base address and the user the key is
     */
    private function owner(User $owner, array $values): array
    {
        $address = $this->addresses->pin($this->client->normalizeAddress($values['url']), $owner);

        return [$address->url, $this->client->owner($address, trim($values['key']))];
    }
}
