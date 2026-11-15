<?php

namespace App\Integrations\Import\Data;

/**
 * The list a row belongs to: the watchlist for its system key, otherwise the
 * user's custom list of that title — created when missing, left untouched
 * when present.
 */
final readonly class ImportListRef
{
    public function __construct(
        public string $title,
        public ?string $systemKey = null,
        public ?string $description = null,
        public bool $isPublic = false,
    ) {}

    /** Identity within one run — the same list is looked up once. */
    public function key(): string
    {
        return $this->systemKey ?? "custom:{$this->title}";
    }
}
