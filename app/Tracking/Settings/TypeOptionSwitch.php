<?php

namespace App\Tracking\Settings;

use App\Tracking\Surface;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where one of a type's options is switched: a surface, the preference it is
 * kept under there, and a default setting it needs on to mean anything (a
 * film's premiere pings only while premieres ping at all).
 */
#[TypeScript]
final readonly class TypeOptionSwitch
{
    public function __construct(
        public Surface $surface,
        public string $preference,
        public ?TypeOptionRequirement $requires = null,
    ) {}
}
