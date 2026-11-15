<?php

use App\Catalog\CatalogueShelf;
use App\Catalog\GameKind;
use App\Catalog\Providers\Igdb\GameType;

test('discover and search ask IGDB for their kinds', function () {
    expect(GameType::clause(GameType::trackable()))->toBe('game_type = (0,1,2,4,8,9,10)')
        ->and(GameType::clause(GameType::searchable()))->toBe('game_type = (0,1,2,3,4,5,8,9,10)')
        ->and(GameType::clause(GameType::cases()))->toBe('game_type = (0,1,2,3,4,5,6,7,8,9,10,11,12,13,14)');
});

test('a kind names its badge and its company shelf', function () {
    expect(GameType::Main->kind())->toBeNull()
        ->and(GameType::StandaloneExpansion->kind())->toBe(GameKind::StandaloneExpansion)
        // Every kind but a main game wears a badge.
        ->and(array_filter(array_map(fn (GameType $type): ?GameKind => $type->kind(), GameType::cases())))->toHaveCount(count(GameKind::cases()))
        ->and(GameType::Expansion->shelf())->toBe(CatalogueShelf::Dlc)
        ->and(GameType::Port->shelf())->toBe(CatalogueShelf::Other)
        ->and(GameType::Remaster->shelf())->toBe(CatalogueShelf::Game);
});
