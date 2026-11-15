<?php

use App\Actions\Library\MarkWatched;
use App\Catalog\Relations\TitleRelation;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\RelatedTitle;
use App\Models\User;
use App\Suggestions\Data\Suggestion;
use App\Suggestions\Sources\RelatedUnseen;

test('it puts forward what is tied to the titles the user finished, saying how', function () {
    $user = User::factory()->create();
    $show = MediaItem::factory()->show()->create(['title' => 'Breaking Bad']);
    $season = MediaItem::factory()->season($show, 1)->create();
    app(MarkWatched::class)->execute($user, MediaItem::factory()->episode($season, 1)->create(), WatchedAt::now());
    refreshTitle($show);
    RelatedTitle::factory()->create(['media_item_id' => $show->id, 'relation' => TitleRelation::SpinOff, 'ref' => '60059', 'title' => 'Better Call Saul', 'release_date' => '2015-02-08']);
    RelatedTitle::factory()->create(['media_item_id' => $show->id, 'relation' => TitleRelation::SpinOff, 'type' => 'movie', 'ref' => '559969', 'title' => 'El Camino', 'release_date' => '2019-10-11']);
    // Tied to a title the user never finished.
    RelatedTitle::factory()->create(['title' => 'Somebody else\'s']);

    $groups = app(RelatedUnseen::class)->groups($user, 'en');

    expect(array_map(fn (Suggestion $suggestion) => [$suggestion->media->title, $suggestion->reason, $suggestion->origins], $groups[0]->suggestions))
        ->toBe([
            ['El Camino', ['Spin-off of Breaking Bad'], ['spin_off']],
            ['Better Call Saul', ['Spin-off of Breaking Bad'], ['spin_off']],
        ]);
});

test('what the user has seen, what is not out yet and a second way to the same title are not repeated', function () {
    $user = User::factory()->create();
    $first = MediaItem::factory()->movie()->create(['title' => 'First']);
    $second = MediaItem::factory()->movie()->create(['title' => 'Second', 'provider_ref' => '2']);
    app(MarkWatched::class)->execute($user, $first, WatchedAt::now());
    app(MarkWatched::class)->execute($user, $second, WatchedAt::now());
    $tie = ['type' => 'movie', 'provider' => 'tmdb'];
    RelatedTitle::factory()->create([...$tie, 'media_item_id' => $first->id, 'relation' => TitleRelation::SpinOff, 'ref' => '2', 'title' => 'Second']);
    RelatedTitle::factory()->create([...$tie, 'media_item_id' => $first->id, 'relation' => TitleRelation::SpinOff, 'ref' => '3', 'title' => 'Third']);
    RelatedTitle::factory()->create([...$tie, 'media_item_id' => $second->id, 'relation' => TitleRelation::SpinOffOf, 'ref' => '3', 'title' => 'Third']);
    RelatedTitle::factory()->create([...$tie, 'media_item_id' => $second->id, 'relation' => TitleRelation::SpinOff, 'ref' => '4', 'release_date' => today()->addYear()->toDateString()]);

    $suggestions = app(RelatedUnseen::class)->groups($user, 'en')[0]->suggestions;

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->media->title)->toBe('Third')
        ->and($suggestions[0]->origins)->toBe(['spin_off', 'spin_off_of']);
});

test('continuations are left to the collections shelf', function () {
    $user = User::factory()->create();
    $film = MediaItem::factory()->movie()->create();
    app(MarkWatched::class)->execute($user, $film, WatchedAt::now());
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::Next]);
    RelatedTitle::factory()->create(['media_item_id' => $film->id, 'relation' => TitleRelation::Previous]);

    expect(app(RelatedUnseen::class)->groups($user, 'en'))->toBe([]);
});

test('the ways in are the kinds of tie, and nothing tied means no shelf', function () {
    $source = app(RelatedUnseen::class);

    expect(array_keys($source->origins()))->toBe(['spin_off', 'spin_off_of'])
        ->and($source->wantedSets(User::factory()->create()))->toBe([])
        ->and($source->groups(User::factory()->create(), 'en'))->toBe([]);
});
