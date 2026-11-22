<?php

use App\Library\LibraryHolders;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\MediaUserState;
use App\Models\Rating;
use App\Models\User;

test('the roots anyone holds are every user\'s, climbed to the title; a collection\'s own list holds none', function () {
    $show = MediaItem::factory()->show()->create();
    $season = MediaItem::factory()->season($show, 1)->create();
    $rated = MediaItem::factory()->movie()->create();
    $opened = MediaItem::factory()->movie()->create();
    $collected = MediaItem::factory()->movie()->create();
    Rating::factory()->for(User::factory())->create(['media_item_id' => $rated->id]);
    MediaUserState::factory()->for(User::factory())->create(['media_item_id' => $opened->id]);
    ListItem::factory()->create(['list_id' => MediaList::factory()->create()->id, 'media_item_id' => $season->id]);
    ListItem::factory()->create(['list_id' => MediaList::factory()->collection('tmdb', '10')->create()->id, 'media_item_id' => $collected->id]);
    projectEveryLibrary();

    expect(app(LibraryHolders::class)->rootsHeldByAnyone()->sort()->values()->all())
        ->toBe(collect([$show->id, $rated->id, $opened->id])->sort()->values()->all());
});
