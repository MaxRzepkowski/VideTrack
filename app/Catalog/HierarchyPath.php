<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsHierarchy;
use App\Catalog\Data\ResolvedMedia;

/**
 * A descendant's ref from its root's ref and the numbers on the way down
 * (season 2, then episode 5). The provider composes every step: ref encoding
 * is its own, and nothing outside it may build one — an import that glued
 * "show:season:episode" itself would break the day the encoding changed.
 */
final readonly class HierarchyPath
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    /**
     * Null when a step leads nowhere: the type has no children here, or the
     * provider cannot compose the ref.
     *
     * @param  list<int>  $numbers
     */
    public function descend(string $rootType, string $rootRef, array $numbers): ?ResolvedMedia
    {
        if (! $this->types->has($rootType)) {
            return null;
        }

        $provider = $this->providers->forType($this->types->get($rootType));
        $type = $rootType;
        $ref = $rootRef;

        foreach ($numbers as $number) {
            $child = $this->types->get($type)->childType;

            if ($child === null || ! $this->types->has($child) || ! $provider instanceof SupportsHierarchy) {
                return null;
            }

            $ref = $provider->childRef($type, $ref, $number);

            if ($ref === null) {
                return null;
            }

            $type = $child;
        }

        return new ResolvedMedia($type, $ref);
    }
}
