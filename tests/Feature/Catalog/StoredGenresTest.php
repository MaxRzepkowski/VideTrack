<?php

use App\Catalog\ItemMetadata;
use App\Catalog\StoredGenres;
use App\Models\MediaItem;

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

    $genres = StoredGenres::of(MediaItem::query()->whereIn('id', [$drama->id, $again->id, $none->id]));

    expect($genres)->toEqualCanonicalizing(['Drama', 'Crime']);
});

test('only names count as genres, and a title with no metadata adds none', function () {
    // Written raw: ItemMetadata never stores anything but names.
    $mixed = MediaItem::factory()->movie()->create(['metadata' => ['genres' => ['Drama', 7, null]]]);
    $bare = MediaItem::factory()->movie()->create();
    MediaItem::query()->whereKey($bare->id)->update(['metadata' => null]);

    expect(StoredGenres::of(MediaItem::query()->whereIn('id', [$mixed->id, $bare->id])))->toBe(['Drama']);
});
