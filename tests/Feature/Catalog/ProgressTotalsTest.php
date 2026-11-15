<?php

use App\Catalog\ProgressTotals;
use App\Catalog\ProgressUnit;
use App\Models\MediaItem;

test('a counted title is left by the share its counter has not reached', function () {
    enableBooksModule();
    $book = MediaItem::factory()->book()->create(['metadata' => [ProgressUnit::Pages->value => 400]]);

    expect(app(ProgressTotals::class)->remainingShare($book, 100))->toBe(0.75)
        ->and(app(ProgressTotals::class)->remainingShare($book, null))->toBe(1.0)
        ->and(app(ProgressTotals::class)->remainingShare($book, 400))->toBeNull();
});

test('a game measures against its time to beat, and a player past it says nothing', function () {
    enableGamesModule();
    $game = MediaItem::factory()->game()->create(['runtime' => 600]);

    expect(app(ProgressTotals::class)->remainingShare($game, 150))->toBe(0.75)
        ->and(app(ProgressTotals::class)->remainingShare($game, 900))->toBeNull()
        ->and(app(ProgressTotals::class)->remainingShare(MediaItem::factory()->game()->create(['runtime' => null]), 10))->toBeNull();
});
