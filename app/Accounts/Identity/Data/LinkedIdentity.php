<?php

namespace App\Accounts\Identity\Data;

use App\Models\Identity;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** An identity the user signs in with, as settings lists it. */
#[TypeScript]
final readonly class LinkedIdentity
{
    public function __construct(
        public int $id,
        public string $provider,
        public ?string $email,
    ) {}

    public static function of(Identity $identity): self
    {
        return new self($identity->id, $identity->provider, $identity->email);
    }
}
