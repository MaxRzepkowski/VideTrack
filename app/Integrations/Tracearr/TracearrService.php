<?php

namespace App\Integrations\Tracearr;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionChoice;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\UserAddresses;
use App\Integrations\Import\Data\CredentialField;
use App\Integrations\Import\Data\CredentialFieldType;
use App\Models\User;

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
        private readonly UserAddresses $addresses,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Tracearr';
    }

    public function connectionFields(): array
    {
        return [
            new CredentialField('url', __('import.tracearr_address'), CredentialFieldType::Url, __('import.the_address_you_open_tracearr_at')),
            new CredentialField('key', __('import.api_key'), CredentialFieldType::Password, __('import.in_tracearr_settings_api_it_starts')),
        ];
    }

    public function verify(User $owner, array $values): array
    {
        $address = $this->addresses->pin($this->client->normalizeAddress($values['url']), $owner);

        return array_map(
            fn (array $user): ConnectionChoice => new ConnectionChoice($user['id'], $user['username']),
            $this->client->users($address, trim($values['key'])),
        );
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        $address = $this->addresses->pin($this->client->normalizeAddress($values['url']), $owner);
        $key = trim($values['key']);

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
