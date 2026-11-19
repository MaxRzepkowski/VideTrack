<?php

namespace App\Actions\Users;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaTypeRegistry;
use App\Enums\ActivitySort;
use App\Library\LibraryStates;
use App\Models\User;
use App\Support\PageInfo;
use App\Users\ActivitySource;
use App\Users\Data\ActivityEntry;
use App\Users\Data\ActivityFilters;
use App\Users\GenreOptions;
use App\Users\OwnerRatings;
use App\Users\PanelReader;

/**
 * The props every activity panel (history, ratings, favorites) sends beside
 * its own entries: the pager, the controls' state and options, and the
 * viewer's state for each card — their action bars act for the viewer, not
 * for the user whose panel it is.
 */
final readonly class BuildActivityPanel
{
    public function __construct(
        private GenreOptions $genres,
        private MediaTypeRegistry $types,
        private LibraryStates $states,
        private OwnerRatings $ownerRatings,
    ) {}

    /**
     * @param  array<int, ActivityEntry>  $entries
     * @return array<string, mixed>
     */
    public function execute(User $viewer, User $user, ActivitySource $source, array $entries, PageInfo $pagination, ActivityFilters $filters): array
    {
        $reader = PanelReader::of($viewer, $user);

        return [
            'entries' => array_values($entries),
            'pagination' => $pagination,
            'filters' => $filters,
            // The axes this panel offers its reader, in the order the control lists them.
            'sortOptions' => array_values(array_map(
                fn (ActivitySort $sort): string => $sort->value,
                array_filter(ActivitySort::cases(), fn (ActivitySort $sort): bool => $source->offers($sort, $reader)),
            )),
            'ownerRatingsShown' => $this->ownerRatings->shown($reader, $filters),
            'genreOptions' => $this->genres->execute($user),
            'typeOptions' => array_keys($this->types->all()),
            'userStates' => $this->states->resolve($viewer, array_map(fn (ActivityEntry $entry): MediaSummary => $entry->media, $entries)),
        ];
    }
}
