<?php

namespace App\Tracking\Settings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A default setting an option's switch needs on, and its name on the page (a translation key). */
#[TypeScript]
final readonly class TypeOptionRequirement
{
    public function __construct(
        public string $preference,
        public string $label,
    ) {}
}
