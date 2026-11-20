<?php

namespace App\Tracking\Releases;

use App\Accounts\Preferences\PreferenceKeys;
use App\Catalog\Contracts\SupportsRelatedReleases;
use App\Catalog\Data\RelatedRelease;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\ProviderRegistry;
use App\Catalog\RelatedReleaseKind;
use App\Models\MediaItem;
use App\Tracking\Settings\TypeOption;
use App\Tracking\Surface;
use Illuminate\Support\Collection;

/**
 * The releases of their own of the tracked games — DLC, expansions, remakes,
 * remasters — beside the games, from what the game's provider listed with
 * them: a release needs no row of its own to be told of. One the user keeps
 * as a title of their own is left to that ({@see OwnStances}).
 */
final readonly class RelatedReleases implements ReleaseSource
{
    public function __construct(
        private ProviderRegistry $providers,
        private PlatformPremieres $premieres,
        private OwnStances $stances,
    ) {}

    public function releases(Collection $roots, ReleaseWindow $window): Collection
    {
        $listed = [];
        $refs = [];

        foreach ($roots as $game) {
            $related = ItemMetadata::fromStored($game->metadata)->relatedReleases ?? [];

            if ($related !== []) {
                $listed[] = [$game, $related];

                foreach ($related as $release) {
                    $refs["{$game->provider}|{$game->type}"][] = $release->ref;
                }
            }
        }

        // A release the surface already carries as a title of its own is told of once, as that.
        $own = [];

        foreach ($roots as $root) {
            $own["{$root->provider}|{$root->type}|{$root->provider_ref}"] = true;
        }

        foreach ($refs as $key => $ofGames) {
            [$provider, $type] = explode('|', $key);

            foreach (array_keys($this->stances->of($window->viewer, $provider, $type, array_values(array_unique($ofGames)))) as $ref) {
                $own["{$key}|{$ref}"] = true;
            }
        }

        $entries = [];

        foreach ($listed as [$game, $related]) {
            foreach ($related as $release) {
                $kind = RelatedReleaseKind::of($release->kind);
                $key = "{$game->provider}|{$game->type}|{$release->ref}";

                if ($kind === null || isset($own[$key]) || ! $window->viewer->preferences->showsRelated($window->surface, $kind)) {
                    continue;
                }

                // A remake two tracked games list is one release.
                $own[$key] = true;

                foreach ($this->entries($game, $release, $window) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        return collect($entries);
    }

    /** Whether the provider of a kind of title lists the releases of their own its titles have. */
    public function serves(MediaTypeDefinition $type): bool
    {
        return $this->providers->forType($type) instanceof SupportsRelatedReleases;
    }

    /**
     * A switch per kind the type's provider lists, on every surface: a DLC is
     * a release of its own, so each surface takes it or not, and a moved
     * date of one is news like the game's.
     */
    public function typeOptions(MediaTypeDefinition $type): array
    {
        $provider = $this->providers->forType($type);

        if (! $provider instanceof SupportsRelatedReleases) {
            return [];
        }

        return array_map(fn (RelatedReleaseKind $kind): TypeOption => TypeOption::on(
            key: 'related_'.$kind->value,
            label: match ($kind) {
                RelatedReleaseKind::Addons => 'settings.dlc_and_expansions',
                RelatedReleaseKind::Remakes => 'settings.remakes',
                RelatedReleaseKind::Remasters => 'settings.remasters',
            },
            description: match ($kind) {
                RelatedReleaseKind::Addons => 'settings.dlc_and_expansions_of_tracked_games',
                RelatedReleaseKind::Remakes => 'settings.remakes_of_tracked_games',
                RelatedReleaseKind::Remasters => 'settings.remasters_of_tracked_games',
            },
            phrase: match ($kind) {
                RelatedReleaseKind::Addons => 'settings.phrase_dlc_and_expansions',
                RelatedReleaseKind::Remakes => 'settings.phrase_remakes',
                RelatedReleaseKind::Remasters => 'settings.phrase_remasters',
            },
            surfaces: Surface::cases(),
            preference: fn (Surface $surface): string => PreferenceKeys::related($surface, $kind),
        ), $provider->relatedReleaseKinds());
    }

    /**
     * Its premieres per platform where the surface takes those, else its
     * first date.
     *
     * @return list<ReleaseEntry>
     */
    private function entries(MediaItem $game, RelatedRelease $release, ReleaseWindow $window): array
    {
        $extra = $window->followedViaOf($game);

        if ($window->viewer->preferences->perPlatform($window->surface) && $release->platformReleases !== []) {
            return array_map(fn (PlatformPremiere $premiere): ReleaseEntry => new ReleaseEntry(
                media: MediaItemSummaries::related(
                    $game,
                    $release,
                    $extra,
                    releaseDate: $premiere->date,
                    platform: $premiere->cardPlatform(),
                    releaseStatus: $premiere->status,
                    releasePrecision: $premiere->precision,
                ),
                rootId: $game->id,
                rootType: $game->type,
            ), $this->premieres->inWindow($release->platformReleases, $window));
        }

        if (! $window->holds($release->date, $release->precision)) {
            return [];
        }

        return [new ReleaseEntry(media: MediaItemSummaries::related($game, $release, $extra), rootId: $game->id, rootType: $game->type)];
    }
}
