<?php

use App\Catalog\Providers\Mal\MangaRelationType;
use App\Catalog\Relations\TitleRelation;

test('MAL\'s relation words become the catalog\'s ties, from this manga\'s side', function (MangaRelationType $type, TitleRelation $relation) {
    expect($type->relation())->toBe($relation);
})->with([
    'what follows' => [MangaRelationType::Sequel, TitleRelation::Next],
    'what came before' => [MangaRelationType::Prequel, TitleRelation::Previous],
    'a side story' => [MangaRelationType::SideStory, TitleRelation::SpinOff],
    'a spin-off' => [MangaRelationType::SpinOff, TitleRelation::SpinOff],
    'the story it branches from' => [MangaRelationType::ParentStory, TitleRelation::SpinOffOf],
    'another setting' => [MangaRelationType::AlternativeSetting, TitleRelation::SameUniverse],
    'another version' => [MangaRelationType::AlternativeVersion, TitleRelation::SameUniverse],
]);

test('the words that say too little are no tie', function (string $word) {
    expect(MangaRelationType::tryFrom($word))->toBeNull();
})->with(['summary', 'full_story', 'adaptation', 'other']);
