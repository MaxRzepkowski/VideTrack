<?php

use App\Catalog\GameKind;
use App\Catalog\RelatedReleaseCard;

test('a release of its own says what it is and whose, in the reader\'s language', function () {
    $card = RelatedReleaseCard::of(['gameType' => GameKind::StandaloneExpansion->value, 'relatedTo' => 'Far Cry 3']);

    expect($card?->facts('en'))->toBe([__('media.standalone_expansion', [], 'en'), 'Far Cry 3'])
        ->and($card?->facts('pl'))->toBe([__('media.standalone_expansion', [], 'pl'), 'Far Cry 3'])
        ->and(__('media.standalone_expansion', [], 'pl'))->not->toBe(__('media.standalone_expansion', [], 'en'));
});

test('every game kind has its words in both languages', function (string $locale) {
    foreach (GameKind::cases() as $kind) {
        expect($kind->label($locale))->not->toStartWith('media.');
    }
})->with(['en', 'pl']);
