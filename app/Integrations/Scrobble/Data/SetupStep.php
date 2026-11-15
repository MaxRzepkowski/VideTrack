<?php

namespace App\Integrations\Scrobble\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One step of "how to point your server at this address", rendered in the
 * integrations card. Sources speak in i18n keys, never in finished sentences:
 * the page is translated, the plugin's own field names are not.
 *
 * `code` is the block the user copies — a template, a URL, a content type.
 * It is shown verbatim in every language.
 */
#[TypeScript]
final readonly class SetupStep
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $code = null,
    ) {}
}
