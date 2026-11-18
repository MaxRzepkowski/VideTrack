<?php

namespace App\Users\Statistics;

use App\Catalog\Department;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Rating;
use App\Models\User;
use App\Support\Numbers;
use App\Support\QueryRow;
use App\Users\Statistics\Data\HistoryEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the statistics read about a period's titles besides their history:
 * the rows themselves, the user's ratings of them and who is credited on
 * them, in chunks a `whereIn` can take.
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

    /**
     * How many of the given titles credit each person in a department, in
     * the given jobs only when `$roles` is not null, and which titles credit
     * anyone that way at all.
     *
     * @param  list<int>  $ids
     * @param  list<string>|null  $roles
     * @return array{people: array<int, int>, titles: list<int>} person id => titles; the titles crediting anyone
     */
    public function credited(array $ids, Department $department, ?array $roles): array
    {
        $people = [];
        $titles = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $credits = DB::table('credits')
                ->whereIn('media_item_id', $chunk)
                ->where('department', $department->value);

            if ($roles !== null) {
                $credits->whereIn('role', $roles);
            }

            // A title's chunk holds all its credits, so counts from different chunks add up.
            foreach ((clone $credits)->groupBy('person_id')->get(['person_id', DB::raw('count(distinct media_item_id) as titles')]) as $row) {
                $person = QueryRow::int($row, 'person_id');
                $people[$person] = ($people[$person] ?? 0) + QueryRow::int($row, 'titles');
            }

            array_push($titles, ...Numbers::ints($credits->distinct()->pluck('media_item_id')));
        }

        return ['people' => $people, 'titles' => $titles];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Person> keyed by id
     */
    public function people(array $ids): Collection
    {
        return Person::query()->whereKey($ids)->get(['id', 'name', 'provider', 'provider_ref', 'profile_path'])->keyBy('id');
    }
}
