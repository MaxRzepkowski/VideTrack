<?php

namespace App\Integrations\Storyteller;

use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;

/**
 * Connecting a Storyteller server: its address and an API token with full
 * access. The token is the user, so there is nobody to pick.
 */
class StorytellerService implements AuthorizesByForm
{
    public const CODE = 'storyteller';

    public function __construct(private readonly StorytellerClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return StorytellerClient::NAME;
    }

    public function connectionFields(): array
    {
        return [
            new CredentialField('url', __('import.service_address', ['service' => $this->name()]), CredentialFieldType::Url, __('import.the_address_you_open_service_at', ['service' => $this->name(), 'example' => 'http://192.168.1.10:8001'])),
            new CredentialField('key', __('import.api_token'), CredentialFieldType::Password, __('import.in_storyteller_preferences_devices_tokens')),
        ];
    }

    public function verify(User $owner, array $values): array
    {
        $this->owner($owner, $values);

        return [];
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        [$url, $username] = $this->owner($owner, $values);

        return new ConnectionGrant(credentials: ['url' => $url, 'key' => $values['key']], accountLabel: $username);
    }

    /**
     * @param  array<string, string>  $values
     * @return array{string, string|null} the base address and the user the token is
     */
    private function owner(User $owner, array $values): array
    {
        $address = $this->client->pin($values['url'], $owner);

        return [$address->url, $this->client->owner($address, $values['key'])];
    }
}
