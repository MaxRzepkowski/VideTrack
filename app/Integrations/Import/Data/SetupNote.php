<?php

namespace App\Integrations\Import\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A line of guidance under an import source, optionally with a link the
 * user follows (to get their export, to make a key). The link shows its
 * address, or $linkText for one whose address says nothing to a reader.
 */
#[TypeScript]
final readonly class SetupNote
{
    public function __construct(
        public string $text,
        public ?string $url = null,
        public ?string $linkText = null,
    ) {}
}
