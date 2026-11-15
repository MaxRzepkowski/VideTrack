<?php

namespace App\Catalog\Data;

/**
 * A provider's answer to "which of these titles changed since then?": the
 * refs it names, or that it cannot tell for that window. Not knowing is part
 * of the answer, not a failure: the caller asks about those titles directly.
 */
final readonly class ChangeReport
{
    /**
     * @param  list<string>|null  $refs  null when the provider cannot tell
     */
    private function __construct(public ?array $refs) {}

    /**
     * @param  list<string>  $refs
     */
    public static function of(array $refs): self
    {
        return new self($refs);
    }

    public static function unknown(): self
    {
        return new self(null);
    }

    public function isKnown(): bool
    {
        return $this->refs !== null;
    }
}
