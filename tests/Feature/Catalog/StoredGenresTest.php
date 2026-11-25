<?php

use App\Catalog\ItemMetadata;
use App\Catalog\StoredGenres;
use App\Models\MediaItem;
use Illuminate\Support\Facades\DB;

/** A title whose metadata holds these genres, stored the way ItemMetadata stores them. */
function titleOfGenres(string ...$genres): MediaItem
{
    return MediaItem::factory()->movie()->create(['metadata' => (new ItemMetadata(genres: $genres))->toStored()]);
}

test('the genres of a set of titles are read once each, only from the titles asked about', function () {
    $drama = titleOfGenres('Drama', 'Crime');
    $again = titleOfGenres('Drama');
    $none = titleOfGenres();
    titleOfGenres('Western');

    $genres = StoredGenres::ofTitles([$drama->id, $again->id, $none->id]);

    expect($genres)->toEqualCanonicalizing(['Drama', 'Crime']);
});

test('only names count as genres, and a title with no metadata adds none', function () {
    // Written raw: ItemMetadata never stores anything but names.
    $mixed = MediaItem::factory()->movie()->create(['metadata' => ['genres' => ['Drama', 7, null]]]);
    $bare = MediaItem::factory()->movie()->create();
    MediaItem::query()->whereKey($bare->id)->update(['metadata' => null]);

    expect(StoredGenres::ofTitles([$mixed->id, $bare->id]))->toBe(['Drama']);
});

test('a set of titles has its genres read from the index, not from each title\'s metadata', function () {
    $titles = [titleOfGenres('Drama')->id, titleOfGenres('Crime')->id];
    $queries = queriesRunBy(fn () => StoredGenres::ofTitles($titles));
    [$read] = $queries;
    expect($queries)->toHaveCount(1);

    $plan = collect(DB::select("explain query plan {$read->sql}", $read->bindings))->pluck('detail')->implode(' | ');

    expect($plan)->toContain('USING COVERING INDEX media_items_id_genres_index');
});
