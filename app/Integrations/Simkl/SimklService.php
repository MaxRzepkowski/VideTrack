<?php

namespace App\Integrations\Simkl;

use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\DeviceGrantClient;
use App\Integrations\Connections\DeviceGrantService;
use App\Integrations\Connections\TokenGrantClient;
use App\Support\Forms\CredentialField;

/**
 * Connecting a Simkl account through AUTH V2's device flow, with the user's
 * own Simkl app: they make one (type "TV, devices & command line") and type
 * its client id on the card, then open simkl.com/pin with the code filled
 * in and approve. Simkl wants an app's id on every call, so the id is kept
 * with the tokens; the instance never sees a password and needs no callback
 * address. The grant asks for media:read only — nothing is ever written back.
 */
class SimklService extends DeviceGrantService
{
    public const CODE = 'simkl';

    public const NAME = 'Simkl';

    public function __construct(DeviceGrantClient $grants, TokenGrantClient $tokens, private readonly SimklClient $client)
    {
        parent::__construct($grants, $tokens);
    }

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
        return [new CredentialField(SimklClient::CLIENT_ID, __('import.simkl_app_client_id'), help: __('import.the_client_id_of_your_simkl_app'))];
    }

    protected function grant(array $values): DeviceGrant
    {
        return $this->client->grant($values[SimklClient::CLIENT_ID]);
    }

    protected function accountName(string $accessToken, array $values): ?string
    {
        return $this->client->accountName($accessToken, $values[SimklClient::CLIENT_ID]);
    }
}
