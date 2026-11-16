<?php

use App\Catalog\Data\MediaSummary;
use App\Catalog\EpisodeBadge;
use App\Enums\ReleaseKind;
use App\Models\User;
use App\Tracking\Releases\ReleaseEntry;
use App\Tracking\Surfaces\FeedItems;

test('an episode is named with its show and code, any other release by its title', function () {
    $items = app(FeedItems::class);
    $episode = new ReleaseEntry(new MediaSummary(type: 'episode', ref: '7:2:5', provider: 'tmdb', title: 'Turn', number: 5, seasonNumber: 2), 7, 'show', 'Alpha');
    $film = new ReleaseEntry(new MediaSummary(type: 'movie', ref: '603', provider: 'tmdb', title: 'Bravo'), 603, 'movie');

    expect($items->title($episode))->toBe('Alpha S02E05 – Turn')
        ->and($items->title($film))->toBe('Bravo');
});

test('the facts under a release follow the calendar card, one per line, the empty ones left out', function () {
    $entry = new ReleaseEntry(new MediaSummary(
        type: 'episode',
        ref: '7:2:1',
        provider: 'tmdb',
        title: 'Opening',
        extra: ['releaseNote' => 'Prime Video', 'followedVia' => 'Alex Doe'],
        number: 1,
        seasonNumber: 2,
        overview: 'Everything starts again.',
        episodeBadge: EpisodeBadge::SeasonPremiere,
    ), 7, 'show', 'Alpha');
    $home = new ReleaseEntry(new MediaSummary(type: 'movie', ref: '603', provider: 'tmdb', title: 'Bravo', releaseKind: ReleaseKind::Home, platform: ''), 603, 'movie');

    expect(app(FeedItems::class)->facts($entry))->toBe([
        'Season premiere',
        'Prime Video',
        __('calendar.because_you_follow_name', ['name' => 'Alex Doe']),
        'Everything starts again.',
    ])
        ->and(app(FeedItems::class)->facts($home))->toBe([__('common.outside_cinemas')]);
});

test('an episode shows its show poster, a title its own', function () {
    $items = app(FeedItems::class);
    $episode = new ReleaseEntry(new MediaSummary(type: 'episode', ref: '7:2:1', provider: 'tmdb', title: 'Opening', posterPath: '/still.jpg', extra: ['showPoster' => '/show.jpg']), 7, 'show', 'Alpha');
    $book = new ReleaseEntry(new MediaSummary(type: 'book', ref: '9', provider: 'hardcover', title: 'Charlie', posterPath: 'https://example.com/cover.jpg'), 9, 'book');

    expect($items->cover($episode))->toEndWith('/show.jpg')
        ->and($items->cover($book))->toBe('https://example.com/cover.jpg');
});

test('a feed is named after the instance and the account', function () {
    $user = User::factory()->create(['username' => 'alex']);

    expect(app(FeedItems::class)->name($user))->toBe(config('app.name').': alex');
});
