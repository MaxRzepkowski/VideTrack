<?php

namespace App\Accounts\Identity\Data;

/** Where an OpenID Connect server signs people in, as its discovery document names it. */
final readonly class OidcEndpoints
{
    public function __construct(
        public string $authorization,
        public string $token,
        public string $userinfo,
    ) {}
}
