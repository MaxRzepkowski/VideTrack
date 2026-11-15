<?php

namespace App\Catalog\Sets;

/**
 * Which set: its kind, the provider that keeps it and the provider's own
 * reference to it (a collection's id).
 */
final readonly class SetKey
{
    public function __construct(
        public SetKind $kind,
        public string $provider,
        public string $ref,
    ) {}

    /** One string per set, to key a map by. */
    public function id(): string
    {
        return "{$this->kind->value}:{$this->provider}:{$this->ref}";
    }
}
