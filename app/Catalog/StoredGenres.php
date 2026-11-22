<?php

namespace App\Catalog;

use App\Models\MediaItem;
use App\Support\Payload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The genre list {@see ItemMetadata} stores on a title, read inside a query:
 * reading it through `ItemMetadata::fromStored()` would build the whole
 * metadata of every row, and a library holds thousands of titles.
 */
final class StoredGenres
{
    /**
     * The distinct genres the rows of a query carry.
     *
     * @param  Builder<MediaItem>  $query
     * @return list<string>
     */
    public static function of(Builder $query): array
    {
        return Payload::strings(DB::query()
            ->fromSub($query->toBase()->select('media_items.metadata'), 'rows')
            ->crossJoin(DB::raw(self::entries('rows.metadata').' as genre'))
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
