<?php

namespace App\Integrations\MdbList;

use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\DeviceGrantClient;
use App\Integrations\Connections\DeviceGrantService;
use App\Integrations\Connections\TokenGrantClient;

/**
 * Connecting an MDBList account through its device code flow: the user
 * types a code at mdblist.com and approves; no key is pasted and no callback
 * address is needed.
 */
class MdbListService extends DeviceGrantService
{
    public const CODE = 'mdblist';

    public const NAME = 'MDBList';

    public function __construct(DeviceGrantClient $grants, TokenGrantClient $tokens, private readonly MdbListClient $client)
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
        return $this->client->username($accessToken);
    }
}
