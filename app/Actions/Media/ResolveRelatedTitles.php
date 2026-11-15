<?php

namespace App\Actions\Media;

use App\Catalog\Relations\RelationStore;
use App\Catalog\Relations\TitleTie;
use App\Models\MediaItem;

/**
 * The titles tied to a title (its spin-offs, what follows it), as its page
 * shows them: read from what the background task stored, never asked for on
 * the way. A title not stored here, or not asked about yet, has none.
 */
final readonly class ResolveRelatedTitles
{
    public function __construct(private RelationStore $relations) {}

    /**
     * @return list<TitleTie>
     */
    public function execute(string $type, string $ref): array
    {
        $title = MediaItem::query()->where('type', $type)->where('provider_ref', $ref)->whereNull('parent_id')->first();

        if ($title === null) {
            return [];
        }

        return $this->relations->of($title);
    }
}
