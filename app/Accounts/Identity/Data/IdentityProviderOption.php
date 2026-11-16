<?php

namespace App\Accounts\Identity\Data;

use App\Accounts\Identity\Contracts\IdentityProvider;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A provider as the sign-in page and settings offer it. */
#[TypeScript]
final readonly class IdentityProviderOption
{
    public function __construct(
        public string $code,
        public string $label,
        public string $glyph,
    ) {}

    public static function of(IdentityProvider $provider): self
    {
        return new self($provider->code(), $provider->label(), $provider->glyph());
    }
}
