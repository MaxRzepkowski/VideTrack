<?php

use App\Catalog\GameKind;
use App\Catalog\RelatedReleaseCard;
use App\Catalog\RelatedReleaseKind;

test('every kind of a game\'s release of its own answers to one switch, other kinds to none', function () {
    $kinds = array_map(fn (GameKind $kind): ?RelatedReleaseKind => RelatedReleaseKind::of($kind), array_combine(array_column(GameKind::cases(), 'value'), GameKind::cases()));

    expect(array_filter($kinds))->toBe([
        'dlc' => RelatedReleaseKind::Addons,
        'expansion' => RelatedReleaseKind::Addons,
        'standalone_expansion' => RelatedReleaseKind::Addons,
        'remake' => RelatedReleaseKind::Remakes,
        'remaster' => RelatedReleaseKind::Remasters,
    ]);
});

test('a card is a release of its own only when it names its game', function () {
    $card = RelatedReleaseCard::of(['gameType' => 'expansion', 'relatedTo' => 'Breath of the Wild']);

    expect($card?->badge)->toBe(GameKind::Expansion)
        ->and($card?->kind())->toBe(RelatedReleaseKind::Addons)
        // A directly tracked DLC wears its badge, but belongs to nobody here.
        ->and(RelatedReleaseCard::of(['gameType' => 'dlc']))->toBeNull()
        ->and(RelatedReleaseCard::of(['relatedTo' => 'Breath of the Wild']))->toBeNull()
        // A badge no switch covers stays a card, answering to no switch.
        ->and(RelatedReleaseCard::of(['gameType' => 'port', 'relatedTo' => 'Breath of the Wild'])?->kind())->toBeNull();
});
