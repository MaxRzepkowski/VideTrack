<?php

namespace App\Integrations\Tracearr;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionChoice;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;

/**
 * Connecting a Tracearr instance: its address and an API key from its
 * Settings → API, then which of the people it watches is this user. The key
 * belongs to Tracearr's owner and sees the whole household, so the choice is
 * kept and every run reads that one person's history only.
 */
class TracearrService implements AuthorizesByForm
{
    public const CODE = 'tracearr';

    public function __construct(
        private readonly TracearrClient $client,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return $this->client->name();
    }

    public function connectionFields(): array
    {
        return [
            new CredentialField('url', __('import.service_address', ['service' => $this->name()]), CredentialFieldType::Url, __('import.the_address_you_open_service_at', ['service' => $this->name(), 'example' => 'http://192.168.1.10:3000'])),
            new CredentialField('key', __('import.api_key'), CredentialFieldType::Password, __('import.in_tracearr_settings_api_it_starts')),
        ];
    }

    public function verify(User $owner, array $values): array
    {
        $address = $this->client->pin($values['url'], $owner);

        return array_map(
            fn (array $user): ConnectionChoice => new ConnectionChoice($user['id'], $user['username']),
            $this->client->users($address, $values['key']),
        );
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        $address = $this->client->pin($values['url'], $owner);
        $key = $values['key'];

        foreach ($this->client->users($address, $key) as $user) {
            if ($user['id'] === $choice) {
                return new ConnectionGrant(
                    credentials: ['url' => $address->url, 'key' => $key],
                    accountLabel: $user['username'],
                    settings: ['user_id' => $user['id']],
                );
            }
        }

        throw new ConnectionRejected('import.pick_which_tracearr_user_you_are');
    }
}
