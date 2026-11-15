<?php

namespace App\Actions\Media;

use App\Catalog\Data\CollectionDetails;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Catalog\TitleStore;
use App\Library\Data\CollectionSyncResult;
use App\Models\ListItem;
use App\Models\MediaList;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates the ownerless system list mirroring a provider
 * collection: title/description from the provider, items = the collection's
 * parts in the provider's order. Used on first visit and by the refresh task.
 *
 * Returns the difference it applied, not just the list — a new part in a
 * followed collection is an event, and this is the only place that knows one
 * arrived.
 */
final readonly class SyncCollectionList
{
    public function __construct(private TitleStore $persist) {}

    public function execute(string $providerCode, CollectionDetails $collection, string $locale): CollectionSyncResult
    {
        // The provider first: persisting a part can ask it, and a network call
        // has no place inside the write below.
        $itemIds = [];

        foreach ($collection->parts as $summary) {
            try {
                $itemIds[] = $this->persist->persist($summary->type, $summary->ref, $locale)->id;
            } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
                // One broken part never blocks the rest of the collection.
            }
        }

        return DB::transaction(function () use ($providerCode, $collection, $itemIds): CollectionSyncResult {
            $list = MediaList::query()->firstOrNew([
                'user_id' => null,
                'system_key' => MediaList::collectionKey($providerCode, $collection->ref),
            ]);

            $wasCreated = ! $list->exists;

            $list->fill([
                'title' => $collection->name,
                'description' => $collection->overview,
                'is_public' => true,
                'backdrop_path' => $collection->backdropPath,
                'collection_relations' => $collection->relations === null || $collection->relations->isEmpty() ? null : $collection->relations->toStored(),
                // Null rather than two empty arrays where the provider served no
                // gallery: the column means "this list has images of its own".
                'images' => $collection->backdrops === [] && $collection->posters === []
                    ? null
                    : ['backdrops' => $collection->backdrops, 'posters' => $collection->posters],
            ])->save();

            $before = $wasCreated ? [] : Numbers::ints($list->items()->pluck('media_item_id'));

            // Parts listed but not one of them stored is a provider that
            // failed on each, not a collection emptied: the parts stand, and
            // the next sync that reaches them says what changed.
            if ($itemIds === [] && $collection->parts !== []) {
                return new CollectionSyncResult(list: $list, addedItemIds: [], removedItemIds: [], wasCreated: $wasCreated);
            }

            // Full sync: parts removed upstream disappear, order follows the
            // provider's order.
            $list->items()->whereNotIn('media_item_id', $itemIds)->delete();

            $now = now();

            ListItem::query()->upsert(
                array_map(fn (int $itemId, int $index): array => [
                    'list_id' => $list->id,
                    'media_item_id' => $itemId,
                    'position' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $itemIds, array_keys($itemIds)),
                ['list_id', 'media_item_id'],
                ['position', 'updated_at'],
            );

            return new CollectionSyncResult(
                list: $list,
                addedItemIds: array_values(array_diff($itemIds, $before)),
                removedItemIds: array_values(array_diff($before, $itemIds)),
                wasCreated: $wasCreated,
            );
        });
    }
}
