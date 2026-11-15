<?php

namespace App\Integrations\Import\Data;

use App\Integrations\Import\ImportInputKind;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An import source's card on the import page: what it reads (a file, a form,
 * a connected account) and how the user gets it there.
 */
#[TypeScript]
final readonly class ImportSourceDef
{
    /**
     * @param  list<CredentialField>  $credentials  the form a credentials source asks to fill in; none otherwise
     * @param  list<SetupNote>  $notes
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $description,
        public ImportInputKind $inputKind,
        /** What the file picker offers, for a file source. */
        public ?string $accept,
        public array $credentials,
        public array $notes,
        public ?ConnectDef $connect,
    ) {}
}
