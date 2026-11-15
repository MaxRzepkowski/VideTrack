<?php

namespace App\Integrations\Connections\Data;

/**
 * What a finished authorization leaves behind: the secrets (encrypted at
 * rest), the choices made while connecting and the account's name.
 */
final readonly class ConnectionGrant
{
    /**
     * @param  array<string, string|int|null>  $credentials
     * @param  array<string, string|int|null>  $settings
     */
    public function __construct(
        public array $credentials,
        public ?string $accountLabel,
        public array $settings = [],
    ) {}
}
