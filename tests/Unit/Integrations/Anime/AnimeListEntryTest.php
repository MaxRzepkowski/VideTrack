<?php

use App\Integrations\Anime\AnimeListEntry;
use App\Integrations\Anime\ListMedium;
use App\Integrations\Export\ListStatus;
use App\Library\RatingScale;

test('a score past our scale is our scale\'s top, and no score stays none', function () {
    expect((new AnimeListEntry(ListMedium::Anime, 1, 'A', ListStatus::Completed, 12))->score)->toBe(RatingScale::MAX)
        ->and((new AnimeListEntry(ListMedium::Anime, 1, 'A', ListStatus::Completed, 7))->score)->toBe(7)
        ->and((new AnimeListEntry(ListMedium::Anime, 1, 'A', ListStatus::Completed))->score)->toBeNull();
});

test('an entry the tracker names no title for is known by its id', function () {
    $entry = new AnimeListEntry(ListMedium::Manga, 44347, null, ListStatus::Planned);

    expect($entry->title)->toBe('MAL 44347')
        ->and(AnimeListEntry::label('One Punch-Man', 44347))->toBe('One Punch-Man (MAL 44347)');
});
