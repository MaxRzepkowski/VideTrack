<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A person behind the period's titles in one craft, and how many of them. */
#[TypeScript]
final readonly class PersonTally
{
    public function __construct(
        public string $name,
        /** Where the person's page is; null for a name with no page (a book's author). */
        public ?string $provider,
        public ?string $ref,
        public ?string $profilePath,
        public int $titles,
    ) {}
}
