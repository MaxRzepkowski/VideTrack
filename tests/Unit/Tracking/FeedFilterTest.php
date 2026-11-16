<?php

use App\Catalog\Data\MediaSummary;
use App\Enums\ReleaseKind;
use App\Tracking\Data\CalendarDay;
use App\Tracking\Data\CalendarEvent;
use App\Tracking\Data\EventEntry;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Surfaces\FeedFilter;
use App\Tracking\Surfaces\FeedScope;

function feedFilm(string $ref, ?ReleaseKind $kind = null): ReleaseEntry
{
    return new ReleaseEntry(new MediaSummary(type: 'movie', ref: $ref, provider: 'tmdb', title: "Film {$ref}", releaseKind: $kind), rootId: (int) $ref, rootType: 'movie');
}

function feedEpisode(string $ref): ReleaseEntry
{
    [, $season, $number] = array_map(intval(...), explode(':', $ref));

    return new ReleaseEntry(
        new MediaSummary(type: 'episode', ref: $ref, provider: 'tmdb', title: "Episode {$ref}", number: $number, seasonNumber: $season),
        rootId: 1,
        rootType: 'show',
        showTitle: 'Alpha',
    );
}

function feedShowcase(string $medium): CalendarEvent
{
    return new CalendarEvent(new EventEntry('igdb', '1', 'Showcase', '2026-10-12T18:00:00+00:00', '2026-10-12T20:00:00+00:00', null, null), $medium);
}

test('with no types and every release, the filter keeps the calendar as it is', function () {
    $days = [new CalendarDay('2026-10-12', [feedFilm('1'), feedEpisode('7:1:2')], [feedShowcase('game')])];

    expect((new FeedFilter)->days($days))->toEqual($days);
});

test('types keep the titles of those types, an episode counting as its show', function () {
    $filter = new FeedFilter(types: ['show']);

    expect($filter->admits(feedEpisode('7:1:2')))->toBeTrue()
        ->and($filter->admits(feedFilm('1')))->toBeFalse()
        ->and($filter->admitsEvent(feedShowcase('game')))->toBeFalse()
        ->and((new FeedFilter(types: ['game']))->admitsEvent(feedShowcase('game')))->toBeTrue();
});

test('premieres keep titles on their premiere and the episodes opening a numbered season, not events', function () {
    $filter = new FeedFilter(scope: FeedScope::Premieres);

    expect($filter->admits(feedFilm('1')))->toBeTrue()
        ->and($filter->admits(feedFilm('1', ReleaseKind::Premiere)))->toBeTrue()
        ->and($filter->admits(feedFilm('1', ReleaseKind::Both)))->toBeTrue()
        ->and($filter->admits(feedFilm('1', ReleaseKind::Home)))->toBeFalse()
        ->and($filter->admits(feedEpisode('7:1:1')))->toBeTrue()
        ->and($filter->admits(feedEpisode('7:2:1')))->toBeTrue()
        ->and($filter->admits(feedEpisode('7:2:3')))->toBeFalse()
        ->and($filter->admits(feedEpisode('7:0:1')))->toBeFalse()
        ->and($filter->admitsEvent(feedShowcase('game')))->toBeFalse();
});

test('a day the variant leaves empty is dropped, the rest keep what it admits', function () {
    $days = [
        new CalendarDay('2026-10-12', [feedEpisode('7:1:2')], [feedShowcase('game')]),
        new CalendarDay('2026-10-13', [feedFilm('1'), feedEpisode('7:1:3')]),
    ];

    $kept = (new FeedFilter(types: ['movie']))->days($days);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->date)->toBe('2026-10-13')
        ->and($kept[0]->entries)->toEqual([feedFilm('1')]);
});

test('equal variants share a cache key, different ones do not', function () {
    expect((new FeedFilter(['movie', 'show'], FeedScope::Premieres))->key())->toBe((new FeedFilter(['movie', 'show'], FeedScope::Premieres))->key())
        ->not->toBe((new FeedFilter(['movie'], FeedScope::Premieres))->key())
        ->not->toBe((new FeedFilter(['movie', 'show']))->key());
});
