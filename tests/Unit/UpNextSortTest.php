<?php

use App\Enums\UpNextSort;
use App\Library\Data\UpNextOrderKeys;

/**
 * @param  list<int|float>|null  $remaining
 */
function shelfEntry(string $title, ?array $remaining = null, ?string $released = null, ?string $activity = null): UpNextOrderKeys
{
    return new UpNextOrderKeys($remaining, $released, $title, $activity);
}

/**
 * @param  list<UpNextOrderKeys>  $entries
 * @return list<string>
 */
function shelfOrder(UpNextSort $sort, array $entries): array
{
    usort($entries, $sort->compare(...));

    return array_map(fn (UpNextOrderKeys $entry): string => $entry->title, $entries);
}

test('the default order is the most recent activity', function () {
    expect(shelfOrder(UpNextSort::Activity, [
        shelfEntry('Old', activity: '2026-01-01'),
        shelfEntry('Never'),
        shelfEntry('New', activity: '2026-03-01'),
    ]))->toBe(['New', 'Old', 'Never']);
});

test('least left first, an entry with nothing to measure by last', function () {
    expect(shelfOrder(UpNextSort::Remaining, [
        shelfEntry('Unknown'),
        shelfEntry('Long', [600, 12]),
        shelfEntry('Short', [30, 1]),
        shelfEntry('Same minutes, fewer episodes', [600, 10]),
    ]))->toBe(['Short', 'Same minutes, fewer episodes', 'Long', 'Unknown']);
});

test('freshest release first, an undated one last', function () {
    expect(shelfOrder(UpNextSort::Released, [
        shelfEntry('Undated'),
        shelfEntry('Older', released: '2025-05-01'),
        shelfEntry('Newer', released: '2026-05-01'),
    ]))->toBe(['Newer', 'Older', 'Undated']);
});

test('titles in natural order, ties falling back to activity', function () {
    expect(shelfOrder(UpNextSort::Title, [
        shelfEntry('Season 10'),
        shelfEntry('season 9', activity: '2026-01-01'),
        shelfEntry('Season 9', activity: '2026-02-01'),
    ]))->toBe(['Season 9', 'season 9', 'Season 10']);
});
