<?php

namespace App\Integrations\Hardcover;

use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\DeviceGrantClient;
use App\Integrations\Connections\DeviceGrantService;
use App\Integrations\Connections\TokenGrantClient;

/**
 * Connecting a Hardcover account through its Device Authorization Grant:
 * the user types a code at hardcover.app/link and approves reading their
 * profile and library. Hardcover asks apps never to take a pasted personal
 * token, and this needs no callback address either.
 */
class HardcoverService extends DeviceGrantService
{
    public const CODE = 'hardcover';

    public const NAME = 'Hardcover';

    public function __construct(DeviceGrantClient $grants, TokenGrantClient $tokens, private readonly HardcoverLibraryClient $client)
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
