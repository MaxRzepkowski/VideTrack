<?php

namespace App\Users\Statistics;

use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use App\Users\Statistics\Data\HistoryEntry;
use Illuminate\Support\Collection;

/**
 * What the statistics read about a period's titles besides their history:
 * the rows themselves and the user's ratings of them, in chunks a `whereIn`
 * can take.
 */
final readonly class TitleLookups
{
    /** Ids per `whereIn`, under SQLite's bound-parameter limit. */
    private const CHUNK = 500;

    /**
     * The titles the entries are filed under, once each.
     *
     * @param  list<HistoryEntry>  $entries
     * @return list<int>
     */
    public static function rootIds(array $entries): array
    {
        return array_values(array_unique(array_map(fn (HistoryEntry $entry): int => $entry->rootId, $entries)));
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>|null  $columns  besides the id; null for the whole row
     * @return Collection<int, MediaItem> keyed by id
     */
    public function items(array $ids, ?array $columns = null): Collection
    {
        $items = new Collection;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (MediaItem::query()->whereKey($chunk)->get($columns === null ? ['*'] : ['id', ...$columns]) as $item) {
                $items->put($item->id, $item);
            }
        }

        return $items;
    }

    /**
     * The user's ratings of the given titles.
     *
     * @param  list<int>  $ids
     * @return array<int, int> title id => rating
     */
    public function ratings(User $user, array $ids): array
    {
        $ratings = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (Rating::query()->where('user_id', $user->id)->whereIn('media_item_id', $chunk)->get(['media_item_id', 'rating']) as $rating) {
                $ratings[$rating->media_item_id] = $rating->rating;
            }
        }

        return $ratings;
    }
}
