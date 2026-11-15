<?php

namespace App\Actions\Media;

use App\Catalog\Hierarchy;
use App\Events\Media\TitleRefreshed;
use App\Library\LibraryHolders;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Event;

/**
 * Seasons and episodes a refresh found gone from the provider — deleted, or
 * merged into another. A part nobody holds anything on goes, so a shortened
 * season stops counting an episode that will never air as one still to
 * watch. A part somebody watched, rated or listed stays: their history is
 * worth more than a tidy tree.
 */
final readonly class RetireGoneParts
{
    public function __construct(
        private Hierarchy $hierarchy,
        private LibraryHolders $holders,
    ) {}

    /**
     * @param  list<int>  $vanished  stored parts the provider no longer lists, as TitleStore::refresh() answers them
     * @return int parts deleted
     */
    public function execute(MediaItem $root, array $vanished): int
    {
        $deleted = 0;

        foreach (MediaItem::query()->whereIn('id', $vanished)->get() as $part) {
            if ($this->holders->isOrphan($this->hierarchy->subtreeIds($part))) {
                $part->delete();
                $deleted++;
            }
        }

        // The tree the library was last projected over had these parts in it.
        if ($deleted > 0) {
            Event::dispatch(new TitleRefreshed($root->id));
        }

        return $deleted;
    }
}
