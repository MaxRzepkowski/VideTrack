<?php

namespace App\Tracking\Releases;

use App\Accounts\Preferences\PreferenceKeys;
use App\Catalog\Contracts\SupportsPlatforms;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\ProviderRegistry;
use App\Models\MediaItem;
use App\Tracking\Settings\TypeOption;
use App\Tracking\Surface;
use Illuminate\Support\Collection;

/**
 * The releases of a title read in progress rather than in one sitting —
 * games, books, manga, comics. Per platform, each premiere (ports, early
 * access, next-gen patches — deliberately unfiltered) is its own entry stamped
 * with the platform and release status; otherwise (or without platform data)
 * the first worldwide date stands in.
 */
final readonly class ProgressReleases implements ReleaseSource
{
    public function __construct(
        private ProviderRegistry $providers,
        private PlatformPremieres $premieres,
    ) {}

    public function releases(Collection $roots, ReleaseWindow $window): Collection
    {
        return $roots
            ->flatMap(fn (MediaItem $title): Collection => $this->entries($title, $window))
            ->values();
    }

    /**
     * Every platform's premiere as its own entry, for a kind of title whose
     * service tells the platforms apart. A notification is about the title,
     * so only the views have the choice.
     */
    public function typeOptions(MediaTypeDefinition $type): array
    {
        if (! $this->providers->forType($type) instanceof SupportsPlatforms) {
            return [];
        }

        return [TypeOption::on(
            key: 'platforms',
            label: 'settings.every_platform_on_its_own',
            description: 'settings.ports_early_access_and_patches_instead',
            phrase: 'settings.phrase_every_platform',
            surfaces: Surface::views(),
            preference: PreferenceKeys::perPlatform(...),
        )];
    }

    /**
     * @return Collection<int, ReleaseEntry>
     */
    private function entries(MediaItem $title, ReleaseWindow $window): Collection
    {
        $releases = $window->viewer->preferences->perPlatform($window->surface) ? ItemMetadata::fromStored($title->metadata)->platformReleases : [];

        if ($releases !== []) {
            return collect($this->premieres->inWindow($releases, $window))
                ->map(fn (PlatformPremiere $premiere): ReleaseEntry => new ReleaseEntry(
                    media: MediaItemSummaries::summary(
                        $title,
                        extra: $window->followedViaOf($title),
                        releaseDate: $premiere->date,
                        platform: $premiere->cardPlatform(),
                        releaseStatus: $premiere->status,
                        releasePrecision: $premiere->precision,
                    ),
                    rootId: $title->id,
                    rootType: $title->type,
                ));
        }

        // No platform data (or the preference is off): the first worldwide
        // date, when it is still ahead. Carry the cover colour (books) so a
        // backdrop-less countdown card tints itself the same as the title's
        // own hero.
        if ($title->release_date !== null && $window->holds($title->release_date->toDateString(), $title->release_date_precision)) {
            return collect([new ReleaseEntry(
                media: MediaItemSummaries::summary($title, [
                    'coverColor' => ItemMetadata::fromStored($title->metadata)->coverColor(),
                    ...$window->followedViaOf($title),
                ]),
                rootId: $title->id,
                rootType: $title->type,
            )]);
        }

        return new Collection;
    }
}
