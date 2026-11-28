<?php

namespace App\Integrations\Import\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An import source that reads through a connected account, as its card on
 * the integrations page shows it.
 */
#[TypeScript]
final readonly class ConnectionSourceData
{
    /**
     * @param  list<SetupNote>  $notes
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $description,
        public array $notes,
        public ConnectDef $connect,
    ) {}
}
