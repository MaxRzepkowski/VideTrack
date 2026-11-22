<?php

namespace App\Actions\Media;

use App\Catalog\Data\MediaDetails;
use App\Catalog\Relations\PageTies;
use App\Catalog\Relations\RelationStore;
use App\Models\MediaItem;

/**
 * The titles tied to a title (its spin-offs, what follows it, its works in
 * other media), as its page shows them: read from what the background task
 * stored, never asked for on the way. A title not stored here, or not asked
 * about yet, shows only the ties stored titles name it by.
 */
final readonly class ResolveRelatedTitles
{
    public function __construct(private RelationStore $relations) {}

    public function execute(MediaDetails $details): PageTies
    {
        $title = MediaItem::query()->where('type', $details->summary->type)->where('provider_ref', $details->summary->ref)->whereNull('parent_id')->first();

        if ($title === null) {
            return PageTies::of($this->relations->ofUnstored($details));
        }

        return PageTies::of($this->relations->of($title));
    }
}
