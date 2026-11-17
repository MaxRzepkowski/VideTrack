<?php

namespace App\Support\Forms;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One field of a form a module describes and the page draws without knowing
 * it: an import source's credentials, a service's sign-in, a notification
 * channel's address. Label and help come worded in the reader's language.
 */
#[TypeScript]
final readonly class CredentialField
{
    public function __construct(
        public string $key,
        public string $label,
        public CredentialFieldType $type = CredentialFieldType::Text,
        public ?string $help = null,
        /** May be left empty: the form asks for it only where it applies. */
        public bool $optional = false,
        /** A credential: once saved, it never comes back to the page; an empty field keeps it. */
        public bool $secret = false,
    ) {}

    public function required(): bool
    {
        return $this->type !== CredentialFieldType::Checkbox && ! $this->optional;
    }
}
