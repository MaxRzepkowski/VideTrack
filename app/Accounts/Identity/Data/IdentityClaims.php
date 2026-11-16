<?php

namespace App\Accounts\Identity\Data;

/**
 * Who an identity provider says a person is. Only the subject is the
 * person: the address and names may change at the provider, and an address
 * counts as theirs only when the provider says it checked it.
 */
final readonly class IdentityClaims
{
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
        /** The person's handle at the provider, a first guess at a username. */
        public ?string $username,
    ) {}
}
