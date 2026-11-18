<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A country or a language the period's titles come from, and how many of them do. */
#[TypeScript]
final readonly class OriginTally
{
    public function __construct(
        /** ISO 3166-1 alpha-2 for a country, ISO 639-1 for a language, as the provider gave it. */
        public string $code,
        public int $titles,
    ) {}
}
