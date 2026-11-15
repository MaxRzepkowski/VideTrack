<?php

namespace App\Integrations\Import\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A line of guidance under an import source: an i18n key, optionally with a
 * link the user follows to get their export. The link is shown verbatim.
 */
#[TypeScript]
final readonly class SetupNote
{
    public function __construct(
        public string $text,
        public ?string $url = null,
    ) {}
}
