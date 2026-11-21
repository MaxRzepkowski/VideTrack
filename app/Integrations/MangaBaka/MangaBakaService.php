<?php

namespace App\Integrations\MangaBaka;

use App\Integrations\Connections\Contracts\AuthorizesByForm;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Models\User;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;

/**
 * Connecting a MangaBaka account with a personal access token its owner
 * makes on mangabaka.org and pastes here: nothing for the operator to
 * register, and the token is the account, so there is nobody to pick.
 */
class MangaBakaService implements AuthorizesByForm
{
    public const CODE = 'mangabaka';

    public const NAME = 'MangaBaka';

    public function __construct(private readonly MangaBakaClient $client) {}

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
            new CredentialField('token', __('import.mangabaka_token'), CredentialFieldType::Password, __('import.make_a_mangabaka_token_under')),
        ];
    }

    public function verify(User $owner, array $values): array
    {
        $this->client->accept(trim($values['token']));

        return [];
    }

    public function connect(User $owner, array $values, ?string $choice): ConnectionGrant
    {
        $token = trim($values['token']);

        return new ConnectionGrant(credentials: ['token' => $token], accountLabel: $this->client->accept($token));
    }
}
