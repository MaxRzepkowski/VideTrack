<?php

namespace App\Actions\Media;

use App\Catalog\Data\CreditData;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Department;
use App\Catalog\Hierarchy;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Catalog\ProviderException;
use App\Models\Credit;
use App\Models\MediaItem;
use Illuminate\Support\Collection;
use LogicException;

/**
 * @phpstan-import-type CardExtra from MediaSummary
 *
 * Rebuilds a detail payload from the database when the provider is
 * unreachable. A provider can go dark for a day, and a library the user has
 * already paid for with their attention should not go blank with it.
 *
 * NOT a first-choice source: pages read the provider and fall back here only
 * after a {@see ProviderException}. The payload is honestly
 * poorer — no recommendations, no images beyond the stored poster, no videos —
 * and the page says so.
 */
final readonly class DetailsFromLibrary
{
    public function __construct(private Hierarchy $hierarchy) {}

    public function execute(MediaItem $item): MediaDetails
    {
        $metadata = ItemMetadata::fromStored($item->metadata);

        [$cast, $crew] = $this->credits($item);

        return new MediaDetails(
            summary: MediaItemSummaries::summary($item, episodeType: $metadata->episodeType, authors: $metadata->authors, episodeBadge: $metadata->episodeBadge),
            tagline: $metadata->tagline,
            overview: $item->overview,
            runtime: $item->runtime,
            genres: $metadata->genres,
            status: $item->status,
            originalLanguage: $metadata->originalLanguage,
            certification: $metadata->certification,
            certifications: $metadata->certifications,
            releases: $metadata->releases,
            platformReleases: $metadata->platformReleases,
            keywords: $metadata->keywords,
            externalLinks: $metadata->externalLinks,
            extra: [...MediaSummary::extraFromStored($metadata->extra), ...$this->treeContext($item)],
            // The stored tree is the whole hierarchy, so a show keeps its
            // season list and a season its episodes even with TMDB down.
            children: array_values($item->children
                ->map(fn (MediaItem $child): MediaSummary => MediaItemSummaries::child($child, $item))
                ->all()),
            cast: $cast,
            crew: $crew,
            wikidataId: $metadata->wikidataId,
            collection: $metadata->collection,
            relatedReleases: $metadata->relatedReleases,
        );
    }

    /**
     * What a page inside a hierarchy links with: back to its root, and to the
     * neighbours either side. The provider sends the same keys with its own
     * payload; a season or episode page cannot even draw its links without
     * them, so the stored tree supplies them here.
     *
     * @return CardExtra
     */
    private function treeContext(MediaItem $item): array
    {
        $parent = $item->parent;

        if ($parent === null) {
            return [];
        }

        $root = $parent->parent;

        // A direct child of the root (a season).
        if ($root === null) {
            // The specials season stands outside the run of seasons the
            // arrows walk.
            $siblings = $parent->children()
                ->whereHas('children')
                ->orderBy('number')
                ->get()
                ->reject(fn (MediaItem $season): bool => $this->hierarchy->isSpecialsChild($parent->type, $season->number))
                ->values();

            return [
                'showRef' => $parent->provider_ref,
                'showTitle' => $parent->title,
                'prevSeason' => $this->seasonNeighbour($siblings, $item, -1),
                'nextSeason' => $this->seasonNeighbour($siblings, $item, 1),
            ];
        }

        // A grandchild (an episode): neighbours within its own season. A
        // season row always carries its number.
        $siblings = $parent->children()->orderBy('number')->get();
        $season = $parent->number ?? throw new LogicException('A season row carries its number.');

        return [
            'showRef' => $root->provider_ref,
            'showTitle' => $root->title,
            'seasonNumber' => $season,
            'seasonTitle' => $parent->title,
            'showBackdrop' => $root->backdrop_path,
            'prev' => $this->episodeNeighbour($siblings, $item, $season, -1),
            'next' => $this->episodeNeighbour($siblings, $item, $season, 1),
        ];
    }

    /**
     * @param  Collection<int, MediaItem>  $siblings
     * @return array{ref: string, number: int, title: string}|null
     */
    private function seasonNeighbour(Collection $siblings, MediaItem $item, int $direction): ?array
    {
        $position = $siblings->values()->search(fn (MediaItem $sibling): bool => $sibling->id === $item->id);
        $neighbour = $position === false ? null : $siblings->values()->get($position + $direction);

        return $neighbour === null
            ? null
            : ['ref' => $neighbour->provider_ref, 'number' => (int) $neighbour->number, 'title' => $neighbour->title];
    }

    /**
     * @param  Collection<int, MediaItem>  $siblings
     * @return array{ref: string, seasonNumber: int, episodeNumber: int, title: string}|null
     */
    private function episodeNeighbour(Collection $siblings, MediaItem $item, int $seasonNumber, int $direction): ?array
    {
        $position = $siblings->values()->search(fn (MediaItem $sibling): bool => $sibling->id === $item->id);
        $neighbour = $position === false ? null : $siblings->values()->get($position + $direction);

        return $neighbour === null
            ? null
            : ['ref' => $neighbour->provider_ref, 'seasonNumber' => $seasonNumber, 'episodeNumber' => (int) $neighbour->number, 'title' => $neighbour->title];
    }

    /**
     * @return array{list<CreditData>, list<CreditData>}
     */
    private function credits(MediaItem $item): array
    {
        $rows = Credit::query()
            ->where('media_item_id', $item->id)
            ->with('person')
            ->orderBy('order')
            ->get();

        $cast = [];
        $crew = [];

        foreach ($rows as $row) {
            $person = $row->person;

            if ($person === null) {
                continue;
            }

            $credit = new CreditData(
                personRef: $person->provider_ref,
                provider: $person->provider,
                name: $person->name,
                profilePath: $person->profile_path,
                department: $row->department,
                role: $row->role,
                order: $row->order,
                episodeCount: $row->episode_count,
                roles: $row->role === null ? [] : [['role' => $row->role, 'episodeCount' => $row->episode_count]],
            );

            if ($row->department === Department::Acting) {
                $cast[] = $credit;

                continue;
            }

            $crew[] = $credit;
        }

        return [$cast, $crew];
    }
}
