<?php

namespace App\Integrations\Import\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One kind of thing an input holds — history, ratings… — with how many.
 * The label arrives translated, in the reader's language.
 */
#[TypeScript]
final readonly class ManifestCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
    ) {}

    /**
     * @return array{key: string, label: string, count: int}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'count' => $this->count];
    }
}
