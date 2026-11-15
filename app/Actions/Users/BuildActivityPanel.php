<?php

namespace App\Actions\Users;

use App\Catalog\Data\MediaSummary;
use App\Catalog\MediaTypeRegistry;
use App\Library\LibraryStates;
use App\Models\User;
use App\Support\PageInfo;
use App\Users\Data\ActivityFilters;
use App\Users\GenreOptions;

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
    ) {}

    /**
     * @param  array<int, array{media: MediaSummary}>  $entries
     * @return array<string, mixed>
     */
    public function execute(User $viewer, User $user, array $entries, PageInfo $pagination, ActivityFilters $filters): array
    {
        return [
            'entries' => array_values($entries),
            'pagination' => $pagination,
            'filters' => $filters,
            'genreOptions' => $this->genres->execute($user),
            'typeOptions' => array_keys($this->types->all()),
            'userStates' => $this->states->resolve($viewer, array_map(fn (array $entry): MediaSummary => $entry['media'], $entries)),
        ];
    }
}
