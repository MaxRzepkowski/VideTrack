<?php

use App\Catalog\Data\MediaSummary;
use App\Catalog\Relations\TieRules;
use App\Catalog\Relations\TitleRelation;
use App\Catalog\Relations\TitleTie;

function providedTie(TitleRelation $relation, string $ref, string $released): TitleTie
{
    return new TitleTie($relation, new MediaSummary(type: 'manga', ref: $ref, provider: 'mal', title: "Manga {$ref}", releaseDate: $released));
}

test('a provider\'s ties show each other title once, by the closest thing known of it, in page order', function () {
    $ties = (new TieRules)->fromProvider([
        providedTie(TitleRelation::SameUniverse, '1', '2001-01-01'),
        providedTie(TitleRelation::Next, '2', '2005-01-01'),
        // The same manga filed twice: as another version and as a side story.
        providedTie(TitleRelation::SpinOff, '1', '2001-01-01'),
        providedTie(TitleRelation::SpinOff, '3', '1999-01-01'),
    ]);

    expect(array_map(fn (TitleTie $tie): array => [$tie->relation, $tie->media->ref], $ties))->toBe([
        [TitleRelation::SpinOff, '3'],
        [TitleRelation::SpinOff, '1'],
        [TitleRelation::Next, '2'],
    ]);
});
