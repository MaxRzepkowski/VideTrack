<?php

namespace App\Library\Data;

use App\Models\MediaList;

/**
 * What one collection sync actually changed. The list alone cannot tell a
 * grown saga from an unchanged one; the "new part in a followed collection" event is exactly that
 * difference.
 *
 * `wasCreated` marks the very first sync (materialization): every part is
 * "added" there, which is a fact about the local database, not about the
 * world — no event may come out of it.
 */
final readonly class CollectionSyncResult
{
    /**
     * @param  list<int>  $addedItemIds  media items new to the list
     * @param  list<int>  $removedItemIds  media items the provider dropped
     */
    public function __construct(
        public MediaList $list,
        public array $addedItemIds,
        public array $removedItemIds,
        public bool $wasCreated,
    ) {}
}
