<?php

namespace App\Accounts\Identity;

use RuntimeException;

/**
 * A rule of the account refused a sign-in through a provider or a change to
 * the ways an account signs in. Answered as a validation error on the
 * `identity` field (bootstrap/app.php); the sign-in callback, which has no
 * form to go back to, sends the person to its own page with it instead.
 */
final class IdentityRefused extends RuntimeException
{
    public const FIELD = 'identity';

    public function __construct(public readonly IdentityRefusal $refusal)
    {
        parent::__construct("Identity refused: {$refusal->value}.");
    }

    public function reason(): string
    {
        return $this->refusal->reason();
    }
}
