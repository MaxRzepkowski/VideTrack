<?php

namespace App\Users;

use App\Catalog\Hierarchy;
use App\Catalog\StoredGenres;
use App\Enums\PlayOutcome;
use App\Models\Favorite;
use App\Models\LibraryEntry;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use App\Support\Numbers;

/**
 * Options of the genre filter: the distinct genre names across the ROOTS of
 * everything a user has played, rated or favorited. The list is the same on
 * every log panel on purpose — switching panels must not silently drop the
 * genre you were filtering by.
 */
final readonly class GenreOptions
{
    public function __construct(private Hierarchy $hierarchy) {}

    /**
     * @return list<string>
     */
    public function execute(User $user): array
    {
        // The projection files every played position under its title; only
        // the few rated and favourite ones climb.
        $played = LibraryEntry::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', Play::query()->select('media_item_id')->where('user_id', $user->id)->where('outcome', PlayOutcome::Completed))
            ->distinct()
            ->pluck('root_id');

        $marked = Rating::query()->where('user_id', $user->id)->pluck('media_item_id')
            ->merge(Favorite::query()->where('user_id', $user->id)->pluck('media_item_id'))
            ->unique();

        $genres = StoredGenres::ofTitles(Numbers::ints($played->merge($this->hierarchy->rootIds(Numbers::ints($marked)))->unique()));

        sort($genres, SORT_NATURAL | SORT_FLAG_CASE);

        return $genres;
    }
}
