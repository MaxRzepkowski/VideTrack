<?php

namespace App\Actions\Media;

use App\Catalog\DatePrecision;
use App\Catalog\ItemMetadata;
use App\Models\MediaItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a root its data source no longer has into an archive entry. The
 * history, ratings and lists on it stay untouched; what goes is every date
 * still ahead, because the source no longer vouches for it (a merged title's
 * future episodes live under the new title now). Without those dates the
 * entry drops out of the calendar, countdowns and release notifications on
 * its own. A refresh that finds the title again restores everything.
 */
final readonly class ArchiveTitle
{
    /**
     * @param  Collection<int, int>  $treeIds  the root and everything under it
     */
    public function execute(MediaItem $root, Collection $treeIds): void
    {
        $today = today()->toDateString();
        // Dates are stored midnight-stamped: one out today is out, not ahead.
        $ahead = MediaItem::airedCutoff(today());

        DB::transaction(function () use ($root, $treeIds, $today, $ahead): void {
            $root->forceFill(['gone_at' => now()])->save();

            MediaItem::query()
                ->whereIn('id', $treeIds)
                ->where('release_date', '>=', $ahead)
                ->update(['release_date' => null, 'release_date_precision' => DatePrecision::Day, 'release_time' => null]);

            MediaItem::query()
                ->whereIn('id', $treeIds)
                ->where('home_release_date', '>=', $ahead)
                ->update(['home_release_date' => null]);

            // Release rows (a film's per country, a game's per platform)
            // carry their own dates.
            MediaItem::query()
                ->whereIn('id', $treeIds)
                ->whereNotNull('metadata')
                ->each(function (MediaItem $item) use ($today): void {
                    $metadata = ItemMetadata::fromStored($item->metadata);
                    $released = $metadata->releasedBy($today);

                    if ($released != $metadata) {
                        $item->forceFill(['metadata' => $released->toStored()])->save();
                    }
                });
        });
    }
}
