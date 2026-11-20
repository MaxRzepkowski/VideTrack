<?php

namespace App\Tracking\Releases;

use App\Accounts\Preferences\UserPreferences;
use App\Catalog\Contracts\SupportsPlatforms;
use App\Catalog\Data\PlatformRelease;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\ProviderRegistry;
use App\Catalog\ReleaseRegion;
use App\Catalog\ViewerRegion;
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
    public function __construct(private ProviderRegistry $providers) {}

    public function releases(Collection $roots, ReleaseWindow $window): Collection
    {
        $viewer = $window->viewer;
        $region = ReleaseRegion::ofCountry(ViewerRegion::resolve($viewer, $viewer->preferredLocale()));

        return $roots
            ->flatMap(fn (MediaItem $title): Collection => $this->entries($title, $window, $region))
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
            preference: UserPreferences::perPlatformKey(...),
        )];
    }

    /**
     * @return Collection<int, ReleaseEntry>
     */
    private function entries(MediaItem $title, ReleaseWindow $window, ReleaseRegion $region): Collection
    {
        $releases = $window->viewer->preferences->perPlatform($window->surface) ? $this->pickRegionalVariants($title, $region) : null;

        if ($releases !== null && $releases->isNotEmpty()) {
            return $releases
                ->filter(fn (PlatformRelease $release) => $window->holds($release->date, $release->precision))
                // Several platforms premiering the same day (with the same
                // release kind) collapse into one entry listing them
                // comma-separated.
                ->groupBy(fn (PlatformRelease $release) => $release->date.'|'.$release->precision->value.'|'.$release->status)
                ->map(function (Collection $group) use ($title, $window): ReleaseEntry {
                    /** @var PlatformRelease $first */
                    $first = $group->first();
                    $platforms = $group->pluck('platform')->filter()->unique()->implode(', ');

                    return new ReleaseEntry(
                        media: MediaItemSummaries::summary(
                            $title,
                            extra: $window->followedViaOf($title),
                            releaseDate: $first->date,
                            platform: $platforms !== '' ? $platforms : null,
                            releaseStatus: $first->status,
                            releasePrecision: $first->precision,
                        ),
                        rootId: $title->id,
                        rootType: $title->type,
                    );
                })
                ->values();
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

    /**
     * Metadata stores every regional date of a platform premiere; the
     * viewer's region picks one per platform + release kind — their region
     * first, then worldwide, then the earliest.
     *
     * @return Collection<int, PlatformRelease>
     */
    private function pickRegionalVariants(MediaItem $title, ReleaseRegion $region): Collection
    {
        $rank = fn (PlatformRelease $release): int => match ($release->region) {
            $region => 0,
            ReleaseRegion::Worldwide => 1,
            default => 9,
        };

        /** @var Collection<int, PlatformRelease> */
        return collect(ItemMetadata::fromStored($title->metadata)->platformReleases)
            ->groupBy(fn (PlatformRelease $release) => $release->platform.'|'.$release->status)
            ->map(fn (Collection $variants) => $variants
                ->sort(fn (PlatformRelease $a, PlatformRelease $b) => [$rank($a), $a->date] <=> [$rank($b), $b->date])
                ->first())
            ->values();
    }
}
