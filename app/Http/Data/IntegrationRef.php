<?php

namespace App\Http\Data;

use App\Integrations\IntegrationKind;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One card of the integrations page by its kind and source code: a server and an account may share a code. */
#[TypeScript]
final readonly class IntegrationRef
{
    public function __construct(
        public IntegrationKind $kind,
        public string $code,
    ) {}
}
