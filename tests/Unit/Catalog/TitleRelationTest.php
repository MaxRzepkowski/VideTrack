<?php

use App\Catalog\Relations\TitleRelation;

test('a tie named from the other title\'s side is its inverse, and the inverse of that is the tie again', function (TitleRelation $relation, TitleRelation $inverse) {
    expect($relation->inverse())->toBe($inverse)
        ->and($relation->inverse()->inverse())->toBe($relation);
})->with([
    'a spin-off sees its original' => [TitleRelation::SpinOff, TitleRelation::SpinOffOf],
    'what follows sees what came before' => [TitleRelation::Next, TitleRelation::Previous],
    'sharing a universe is the same from both sides' => [TitleRelation::SameUniverse, TitleRelation::SameUniverse],
]);

test('every tie has an inverse', function () {
    foreach (TitleRelation::cases() as $relation) {
        expect($relation->inverse())->toBeInstanceOf(TitleRelation::class);
    }
});
