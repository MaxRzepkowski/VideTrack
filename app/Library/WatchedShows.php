<?php

namespace App\Library;

use App\Catalog\MediaTypeRegistry;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The shows a user is watching: anything logged in them at all, and no
 * status on the title — dropped in the cycle it is in now, or paused, it
 * leaves Up Next and the progress shelf, its history intact.
 */
final readonly class WatchedShows
{
    public function __construct(private MediaTypeRegistry $types) {}

    /**
     * @return Collection<int, MediaItem>
     */
    public function of(User $user): Collection
    {
        $containers = $this->types->containerRootCodes();

        return MediaItem::query()
            ->whereIn('type', $containers)
            ->whereIn('id', LibraryEntry::query()
                ->select('root_id')
                ->where('user_id', $user->id)
                ->ofRoots()
                ->where('played', true)
                ->whereNull('status'))
            ->orderBy('id')
            ->get();
    }
}
