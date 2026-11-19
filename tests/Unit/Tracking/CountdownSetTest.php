<?php

use App\Catalog\Data\MediaSummary;
use App\Enums\CountdownSort;
use App\Enums\SortDirection;
use App\Tracking\Data\CountdownEntry;
use App\Tracking\Data\CountdownFilters;
use App\Tracking\Data\EventCountdown;
use App\Tracking\Data\EventEntry;
use App\Tracking\Surfaces\CountdownSet;

/** A set of a show's next episode, a movie and an event, soonest first. */
function countdownSet(): CountdownSet
{
    $card = fn (string $type, string $title, ?string $show = null): array => [
        'type' => $show === null ? $type : 'show',
        'title' => $show ?? $title,
        'names' => [$show, $title],
        'card' => new CountdownEntry(new MediaSummary(type: $type, ref: $title, provider: 'tmdb', title: $title), $show, $show === null ? $type : 'show'),
    ];
    $event = new EventEntry('igdb', '1', 'Showcase', '2026-10-14T18:00:00+00:00', '2026-10-14T19:00:00+00:00', null, null);

    return new CountdownSet([
        $card('episode', 'Pilot', 'Severance'),
        ['type' => EventCountdown::FILTER_TYPE, 'title' => 'Showcase', 'names' => ['Showcase'], 'card' => new EventCountdown($event, 'game', EventCountdown::FILTER_TYPE)],
        $card('movie', 'Dune'),
    ]);
}

/** @param  list<CountdownEntry|EventCountdown>  $entries */
function countdownNames(array $entries): array
{
    return array_map(fn (CountdownEntry|EventCountdown $entry): string => $entry instanceof EventCountdown ? $entry->event->name : $entry->media->title, $entries);
}

test('the set keeps its soonest-first order and counts every kind', function () {
    $set = countdownSet();

    expect(countdownNames($set->matching(new CountdownFilters)))->toBe(['Pilot', 'Showcase', 'Dune'])
        ->and($set->counts())->toBe(['show' => 1, EventCountdown::FILTER_TYPE => 1, 'movie' => 1]);
});

test("a search hits a show's name as well as the episode's own", function () {
    expect(countdownNames(countdownSet()->matching(new CountdownFilters(q: 'sever'))))->toBe(['Pilot'])
        ->and(countdownNames(countdownSet()->matching(new CountdownFilters(q: 'DUNE'))))->toBe(['Dune']);
});

test('a kind narrows, and a kind with nothing counting down leaves nothing', function () {
    expect(countdownNames(countdownSet()->matching(new CountdownFilters(type: EventCountdown::FILTER_TYPE))))->toBe(['Showcase'])
        ->and(countdownSet()->matching(new CountdownFilters(type: 'game')))->toBe([]);
});

test('the title sort orders by the show for an episode, either way', function () {
    expect(countdownNames(countdownSet()->matching(new CountdownFilters(sort: CountdownSort::Title))))->toBe(['Dune', 'Pilot', 'Showcase'])
        ->and(countdownNames(countdownSet()->matching(new CountdownFilters(dir: SortDirection::Desc))))->toBe(['Dune', 'Showcase', 'Pilot']);
});

test('a page holds its slice and the whole count; one past the end is empty', function () {
    $page = countdownSet()->page(new CountdownFilters, 2, 2);

    expect(countdownNames(array_values($page->items())))->toBe(['Dune'])
        ->and($page->total())->toBe(3)
        ->and($page->lastPage())->toBe(2)
        ->and(countdownSet()->page(new CountdownFilters, 3, 2)->items())->toBe([]);
});
