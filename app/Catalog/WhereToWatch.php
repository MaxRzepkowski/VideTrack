<?php

namespace App\Catalog;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsWatchProviders;
use App\Catalog\Data\WatchAvailability;
use App\Models\User;

/**
 * "Where to Watch" offers. A provider without the capability, or one that
 * fails, only hides the section — it never breaks the title page.
 */
final readonly class WhereToWatch
{
    /** Offers in the viewer's region. */
    public function forViewer(MetadataProvider $provider, string $type, string $ref, User $viewer, string $locale): ?WatchAvailability
    {
        if (! $provider instanceof SupportsWatchProviders) {
            return null;
        }

        // A title the provider has no offers page for is a title on offer nowhere.
        return (new ProviderCalls)->attempt(fn (): ?WatchAvailability => $provider->watchProviders($type, $ref, ViewerRegion::resolve($viewer, $locale)), null);
    }

    /**
     * Every region with an offer, ignoring the viewer's own — the question is
     * whether the title is watchable ANYWHERE, which is what tells a film
     * merely missing a digital release date apart from one genuinely still
     * confined to cinemas. Same cached response as forViewer(), so it is free.
     *
     * @return list<string>
     */
    public function regionsWithOffers(MetadataProvider $provider, string $type, string $ref): array
    {
        if (! $provider instanceof SupportsWatchProviders) {
            return [];
        }

        return (new ProviderCalls)->attempt(fn (): array => $provider->watchProviderRegions($type, $ref), []);
    }
}
