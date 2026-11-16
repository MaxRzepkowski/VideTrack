<?php

namespace App\Integrations\Import\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One kind of thing an input holds — history, ratings… — with how many.
 * The label arrives translated, in the reader's language. $preselected says
 * whether the import page ticks it at first; one the user has to choose for
 * themselves waits unticked — a reading of the file it does not say outright
 * (a rated film taken for a watched one), titles that would only fill a list,
 * one person's history among a household's.
 */
#[TypeScript]
final readonly class ManifestCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public bool $preselected = true,
    ) {}

    /** The same category, left for the user to tick. */
    public function optIn(): self
    {
        return new self($this->key, $this->label, $this->count, preselected: false);
    }

    /**
     * @return array{key: string, label: string, count: int, preselected: bool}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'count' => $this->count, 'preselected' => $this->preselected];
    }
}
