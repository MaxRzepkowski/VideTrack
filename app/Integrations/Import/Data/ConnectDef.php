<?php

namespace App\Integrations\Import\Data;

use App\Integrations\Connections\ConnectMethod;
use App\Integrations\Connections\Data\ConnectionCard;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How an import source's card connects to its service (a code typed at the
 * service, or a pasted address and key) and the account it holds there.
 */
#[TypeScript]
final readonly class ConnectDef
{
    /**
     * @param  list<CredentialField>  $fields  what a form-authorised service asks for; none for a code flow
     */
    public function __construct(
        public string $service,
        public string $name,
        public ConnectMethod $method,
        public array $fields,
        public int $autoImportMinutes,
        public ?ConnectionCard $connection,
    ) {}
}
