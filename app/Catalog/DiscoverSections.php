<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsTrending;
use App\Catalog\Data\BrowseSections;
use App\Catalog\Data\MediaSummary;

/**
 * The browse sections of one type: what is trending, popular and coming up
 * at the type's provider, each cut to a short row. A section the provider
 * cannot fill is empty; one it fails to fill degrades through the caller's
 * ProviderCalls. Trending is shared by every viewer; the other two go as far
 * as the viewer lets the provider go.
 */
final readonly class DiscoverSections
{
    /** Two full rows of the five-column browse grid. */
    private const SIZE = 10;

    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    public function of(string $type, string $locale, MatureChoices $mature, ProviderCalls $calls): BrowseSections
    {
        $provider = $this->providers->forType($this->types->get($type));
        $trending = $popular = $upcoming = [];

        if ($provider instanceof SupportsTrending) {
            // A provider's trending can span types (TMDB mixes movies and
            // shows) — keep only the one being browsed.
            $items = $calls->attempt(fn (): array => $provider->trending($locale), []);
            $trending = array_slice(
                array_values(array_filter($items, fn (MediaSummary $item): bool => $item->type === $type)),
                0,
                self::SIZE,
            );
        }

        if (Discovery::supports($provider, $type)) {
            $level = $mature->for($provider->code());
            $popular = array_slice($calls->attempt(fn (): array => $provider->popular($type, $locale, $level), []), 0, self::SIZE);
            $upcoming = array_slice($calls->attempt(fn (): array => $provider->upcoming($type, $locale, $level), []), 0, self::SIZE);
        }

        return new BrowseSections($trending, $popular, $upcoming);
    }
}
