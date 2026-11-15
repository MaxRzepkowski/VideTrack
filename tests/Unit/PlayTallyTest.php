<?php

use App\Library\Data\PlayTally;

function tally(int $completions, ?string $watchedKey, ?string $droppedKey, ?int $totalAmount = null): PlayTally
{
    return new PlayTally(
        entries: $completions + ($droppedKey === null ? 0 : 1),
        completions: $completions,
        lastWatchedAt: $watchedKey === null ? null : substr($watchedKey, 0, 10),
        lastDroppedAt: $droppedKey === null ? null : substr($droppedKey, 0, 10),
        lastWatchedKey: $watchedKey,
        lastDroppedKey: $droppedKey,
        droppedAmount: null,
        totalAmount: $totalAmount,
    );
}

test('cycles merge into one history: counts add up, the newest moments win', function () {
    $merged = PlayTally::merge([
        tally(1, '2025-01-01#1', null, 120),
        tally(2, '2026-01-01#5', '2025-06-01#3', null),
    ]);

    expect($merged->entries)->toBe(4)
        ->and($merged->completions)->toBe(3)
        ->and($merged->lastWatchedAt)->toBe('2026-01-01')
        ->and($merged->lastDroppedAt)->toBe('2025-06-01')
        ->and($merged->totalAmount)->toBe(120);
});

test('a history with no recorded cost has none, rather than zero', function () {
    expect(PlayTally::merge([tally(1, '2025-01-01#1', null)])->totalAmount)->toBeNull();
});

test('the newest entry decides whether the attempt reads as dropped', function () {
    expect(tally(0, null, '2026-01-01#2')->latestIsDrop())->toBeTrue()
        ->and(tally(1, '2026-01-01#1', '2026-01-01#2')->latestIsDrop())->toBeTrue()
        // Same moment, created after the drop: the completion is the newer entry.
        ->and(tally(1, '2026-01-01#3', '2026-01-01#2')->latestIsDrop())->toBeFalse()
        ->and(tally(1, '2026-01-01#1', null)->latestIsDrop())->toBeFalse();
});
