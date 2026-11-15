<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;

/**
 * Capability: the provider's types form a parent → child hierarchy
 * (show → season → episode). Flat types (movies, books) don't need this.
 */
interface SupportsHierarchy
{
    /**
     * Ref of the parent position (episode → season, season → show); null for
     * roots. Ref encoding stays provider-private — the core never parses refs.
     */
    public function parentRef(string $type, string $ref): ?string;

    /**
     * The mirror of {@see parentRef()}: the ref of a numbered child (season 2
     * of a show, episode 5 of a season), composed by the PROVIDER because ref
     * encoding is its own. Null when the type has no children.
     *
     * Existence is not checked here — a ref for an episode that never aired
     * is a ref that resolves to nothing later, which is the caller's problem
     * and not worth a round-trip at composition time.
     */
    public function childRef(string $type, string $ref, int $number): ?string;

    /**
     * Every leaf below a root (a show: each season's episodes, specials
     * included — a caller that excludes them narrows the result itself, from
     * the branch each leaf names), in provider order, in as few requests as
     * the provider can manage. Each leaf carries every field a stored tree
     * keeps: the title store builds the tree's leaves from here, grouped
     * under their parents by {@see parentRef()}. Whole-tree values of items
     * the user has not tracked yet — the total runtime — read through this
     * too. Empty for a type without grandchildren.
     *
     * @return list<MediaSummary>
     */
    public function leaves(string $type, string $ref, string $locale): array;
}
