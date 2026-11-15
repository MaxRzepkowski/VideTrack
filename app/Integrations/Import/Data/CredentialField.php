<?php

namespace App\Integrations\Import\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One field of a credentials source's form. Label and help are i18n keys.
 */
#[TypeScript]
final readonly class CredentialField
{
    public function __construct(
        public string $key,
        public string $label,
        public CredentialFieldType $type = CredentialFieldType::Text,
        public ?string $help = null,
    ) {}

    public function required(): bool
    {
        return $this->type !== CredentialFieldType::Checkbox;
    }
}
