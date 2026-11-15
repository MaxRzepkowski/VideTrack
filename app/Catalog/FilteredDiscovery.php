<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsFilteredDiscovery;

/**
 * The "can this provider filter this type?" check — the capability guard plus
 * the per-type membership, in one place. Twin of Discovery::supports().
 */
final class FilteredDiscovery
{
    /**
     * @phpstan-assert-if-true SupportsFilteredDiscovery $provider
     */
    public static function supports(object $provider, string $type): bool
    {
        return $provider instanceof SupportsFilteredDiscovery
            && in_array($type, $provider->filterableTypes(), true);
    }
}
