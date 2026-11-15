<?php

namespace App\Catalog\Creators;

use App\Catalog\Data\MediaSummary;

/**
 * A creator as the outside world names it: its kind and its id at the
 * provider. A studio and a network can share a numeric ref, so the kind is
 * part of the identity.
 */
final readonly class CreatorRef
{
    public function __construct(
        public CreatorKind $kind,
        public string $provider,
        public string $ref,
    ) {}

    /**
     * The creator a card stands for — search results, list positions and
     * discover rows carry people and companies as creator cards
     * ({@see CreatorKind::PERSON_CARD}). Null for a title.
     */
    public static function fromSummary(MediaSummary $summary): ?self
    {
        $kind = match ($summary->type) {
            CreatorKind::PERSON_CARD => CreatorKind::Person,
            CreatorKind::COMPANY_CARD => $summary->creatorKind ?? CreatorKind::Company,
            default => null,
        };

        return $kind === null ? null : new self($kind, $summary->provider, $summary->ref);
    }

    /** The one spelling of a creator in maps keyed by creator: "kind:provider:ref". */
    public function key(): string
    {
        return "{$this->kind->value}:{$this->provider}:{$this->ref}";
    }
}
