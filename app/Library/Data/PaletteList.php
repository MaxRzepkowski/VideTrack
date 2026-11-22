<?php

namespace App\Library\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A list the command palette offers to open: one of the viewer's, or a collection the instance keeps. */
#[TypeScript]
final readonly class PaletteList
{
    public function __construct(
        public int $id,
        public string $title,
        /** Where the list lives: under its owner, or at the collection's own address. */
        public string $href,
        public int $itemCount,
        public bool $isCollection,
    ) {}
}
