<?php

namespace Tests\Fakes;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionChoice;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Import\Data\CredentialField;
use App\Integrations\Import\Data\CredentialFieldType;
use App\Models\User;

/**
 * A pasted-key service: "good-key" is accepted, anything else is turned
 * down; it holds two accounts to pick from.
 */
class FakeFormService implements AuthorizesByForm
{
    public const CODE = 'fake-form';

    public static int $verifications = 0;

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Fake Form';
    }

    public function connectionFields(): array
    {
        return [new CredentialField('url', 'Address'), new CredentialField('key', 'API key', CredentialFieldType::Password)];
    }

    public function verify(User $owner, array $values): array
    {
        self::$verifications++;

        if ($values['key'] !== 'good-key') {
            throw new ConnectionRejected('The key was not accepted.');
        }

        return [new ConnectionChoice('u-1', 'alice'), new ConnectionChoice('u-2', 'bob')];
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        $accounts = [];

        foreach ($this->verify($owner, $values) as $account) {
            $accounts[$account->id] = $account->label;
        }

        if ($choice === null || ! isset($accounts[$choice])) {
            throw new ConnectionRejected('Pick your account.');
        }

        return new ConnectionGrant(
            credentials: ['url' => $values['url'], 'key' => $values['key']],
            accountLabel: $accounts[$choice],
            settings: ['user_id' => $choice],
        );
    }
}
