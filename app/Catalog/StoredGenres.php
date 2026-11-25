<?php

namespace App\Catalog;

use App\Support\Payload;
use Illuminate\Support\Facades\DB;

/**
 * The genre list {@see ItemMetadata} stores on a title, read inside a query:
 * reading it through `ItemMetadata::fromStored()` would build the whole
 * metadata of every row, and a library holds thousands of titles.
 */
final class StoredGenres
{
    /**
     * The index holding each title's genre list beside its id, as the query
     * builder writes the genres column: read so, the genres come from the
     * index without parsing any row's metadata.
     */
    private const INDEX = 'media_items_id_genres_index';

    /**
     * The distinct genres of these titles.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    public static function ofTitles(array $ids): array
    {
        // Named, not left to the planner: nothing gathers statistics on this
        // database, and without them SQLite reads each row by its key instead.
        $titles = DB::table(DB::raw('media_items indexed by '.self::INDEX))
            ->whereIn('id', $ids)
            ->select(ItemMetadata::column('genres').' as genres');

        return Payload::strings(DB::query()
            ->fromSub($titles, 'rows')
            ->crossJoin(DB::raw('json_each(rows.genres) as genre'))
            ->distinct()
            ->pluck('genre.value')
            ->all());
    }

    /**
     * The genre list of a metadata column (or an expression choosing one) as a
     * table of entries a query can join or search: SQLite's `json_each`, one
     * row per genre, `value` holding its name.
     *
     * @param  literal-string  $metadata
     * @return literal-string
     */
    public static function entries(string $metadata): string
    {
        return "json_each(coalesce({$metadata}, '{}'), '$.genres')";
    }
}
