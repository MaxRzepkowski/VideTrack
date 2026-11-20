<?php

namespace App\Tracking\Settings;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A kind of title on the tracking page, with the settings only it has. */
#[TypeScript]
final readonly class TypePanel
{
    /**
     * @param  list<TypeOption>  $options
     */
    public function __construct(
        public string $type,
        public array $options,
    ) {}
}
