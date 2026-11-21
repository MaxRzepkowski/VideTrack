<?php

namespace App\Integrations\Simkl;

use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\DeviceGrantClient;
use App\Integrations\Connections\DeviceGrantService;
use App\Integrations\Connections\TokenGrantClient;

/**
 * Connecting a Simkl account through AUTH V2's device flow: the user opens
 * simkl.com/pin with the code filled in and approves; the instance never
 * sees a password and needs no callback address. The grant asks for
 * media:read only — nothing is ever written back.
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

    protected function grant(): DeviceGrant
    {
        return $this->client->grant();
    }

    protected function accountName(string $accessToken): ?string
    {
        return $this->client->accountName($accessToken);
    }
}
