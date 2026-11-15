<?php

namespace App\Actions\Media;

use App\Catalog\GoneTitleFate;
use App\Catalog\Hierarchy;
use App\Library\LibraryHolders;
use App\Models\MediaItem;

/**
 * A root its data source answers "not found" for. One nobody attached
 * anything to (a title merely opened once, or an archive entry whose last
 * play, rating or list entry was removed since) is deleted. One with real
 * history, ratings or lists is KEPT as an archive entry: TMDB 404s a merged
 * duplicate whose content lives under another id, so deleting attached data
 * would lose it.
 */
final readonly class RetireGoneTitle
{
    public function __construct(
        private Hierarchy $hierarchy,
        private LibraryHolders $holders,
        private ArchiveTitle $archive,
    ) {}

    public function execute(MediaItem $root): GoneTitleFate
    {
        $tree = $this->hierarchy->subtreeIds($root);

        if ($this->holders->isOrphan($tree)) {
            $root->delete();

            return GoneTitleFate::Deleted;
        }

        if ($root->gone_at !== null) {
            return GoneTitleFate::StillArchived;
        }

        $this->archive->execute($root, $tree);

        return GoneTitleFate::Archived;
    }
}
