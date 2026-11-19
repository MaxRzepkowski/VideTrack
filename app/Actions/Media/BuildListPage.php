<?php

namespace App\Actions\Media;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CollectionRelations;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Hierarchy;
use App\Catalog\ItemMetadata;
use App\Catalog\MediaItemSummaries;
use App\Catalog\MediaTypeRegistry;
use App\Library\CreatorStateQuery;
use App\Library\Data\ListDetailsData;
use App\Library\Data\ListItemEntry;
use App\Library\LibraryStates;
use App\Models\ListItem;
use App\Models\MediaList;
use App\Models\User;

/**
 * A list's page: its header, its positions as cards (titles, and people
 * and companies as person- and company-typed summaries), the statistics strip, and the viewer's state for
 * every card.
 */
final readonly class BuildListPage
{
    public function __construct(
        private LibraryStates $states,
        private CreatorStateQuery $creatorStates,
        private BuildListStatistics $statistics,
        private MediaItemSummaries $cards,
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private BuildCollectionOverview $collectionOverview,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $viewer, MediaList $list): array
    {
        $list->load(['user', 'items.mediaItem.parent.parent', 'items.person', 'items.company']);

        $items = $list->items->map(function (ListItem $item): ?ListItemEntry {
            // Person positions ride as a person-typed summary — the list page
            // renders them as portrait cards without an action bar.
            if ($item->person !== null) {
                return new ListItemEntry(
                    id: $item->id,
                    addedAt: $item->created_at?->toIso8601String(),
                    genres: [],
                    mediaItemId: null,
                    imdbId: null,
                    media: new MediaSummary(
                        type: CreatorKind::PERSON_CARD,
                        ref: $item->person->provider_ref,
                        provider: $item->person->provider,
                        title: $item->person->name,
                        posterPath: $item->person->profile_path,
                    ),
                );
            }

            // Company positions (studio / publisher / network) ride the same way.
            if ($item->company !== null) {
                return new ListItemEntry(
                    id: $item->id,
                    addedAt: $item->created_at?->toIso8601String(),
                    genres: [],
                    mediaItemId: null,
                    imdbId: null,
                    media: new MediaSummary(
                        type: CreatorKind::COMPANY_CARD,
                        ref: $item->company->provider_ref,
                        provider: $item->company->provider,
                        title: $item->company->name,
                        posterPath: $item->company->logo_path,
                        creatorKind: CreatorKind::from($item->company->kind),
                        makes: $this->types->rootServedBy($item->company->provider),
                    ),
                );
            }

            $media = $item->mediaItem;

            if ($media === null) {
                return null;
            }

            return new ListItemEntry(
                id: $item->id,
                addedAt: $item->created_at?->toIso8601String(),
                // The genre filter reads the same genres the log panels filter
                // by: a season or an episode is browsed under its show's, so
                // the root carries them (grandparent → parent → self, the
                // order TitleFilters' genre clause coalesces in).
                genres: ItemMetadata::fromStored($this->hierarchy->loadedRootOf($media)->metadata)->genres,
                mediaItemId: $media->id,
                imdbId: ItemMetadata::fromStored($media->metadata)->imdbId(),
                media: $this->cards->inContext($media),
            );
        })->filter()->values();

        $summaries = $items->map(fn (ListItemEntry $item): MediaSummary => $item->media);
        $relations = $list->isCollection() && $list->collection_relations !== null ? CollectionRelations::fromStored($list->collection_relations) : null;
        // People and companies sit outside the media type registry — no state.
        $userStates = $this->states->resolve(
            $viewer,
            $summaries->filter(fn (MediaSummary $media) => $this->types->has($media->type)),
        );

        return [
            'list' => new ListDetailsData(
                id: $list->id,
                title: $list->title,
                description: $list->description,
                systemKey: $list->system_key,
                displayTitle: $list->displayTitle(),
                collection: $list->collectionSource(),
                isPublic: $list->is_public,
                backdropPath: $list->backdrop_path,
                // Where the provider serves one: the same Photos section a
                // title's page carries.
                backdrops: $list->images['backdrops'] ?? [],
                posters: $list->images['posters'] ?? [],
                owner: $list->user === null ? null : [
                    'id' => $list->user->id,
                    'name' => $list->user->name,
                    'username' => $list->user->username,
                    'avatar' => $list->user->avatar,
                ],
                isOwner: $list->user_id !== null && $list->user_id === $viewer->id,
                canDelete: $viewer->can('delete', $list),
                followMode: $list->isCollection()
                    ? $viewer->follows()->where('list_id', $list->id)->first()?->mode
                    : null,
                collectionRelations: $relations,
            ),
            'items' => $items,
            'stats' => $this->statistics->execute($summaries, $userStates),
            // The saga as a whole; a list a user typed out has none.
            'collectionOverview' => $this->collectionOverview->execute($list),
            'userStates' => $userStates,
            // The person / company cards' counterpart: the bell and the lists.
            'creatorStates' => $this->creatorStates->ofSummaries($viewer, $summaries),
        ];
    }
}
