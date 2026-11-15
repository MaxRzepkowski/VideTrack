<?php

use App\Actions\Library\SyncListMembership;
use App\Library\Data\ListItemEntry;
use App\Models\MediaItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('list positions carry the total runtime used to sort every supported media type', function () {
    $user = User::factory()->create();
    $list = $user->watchlist();

    $show = MediaItem::factory()->show()->create(['title' => 'A series', 'total_runtime' => 4000]);
    $movie = MediaItem::factory()->movie()->create(['title' => 'A film', 'runtime' => 120, 'total_runtime' => 120]);

    app(SyncListMembership::class)->execute($user, $show, [$list->id]);
    app(SyncListMembership::class)->execute($user, $movie, [$list->id]);

    $items = collect(
        $this->actingAs($user)
            ->get(listPage($list))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('lists/ListDetails'))
            ->viewData('page')['props']['items']
    )->keyBy(fn (ListItemEntry $item) => $item->media->type);

    expect($items['show']->media->totalRuntime)->toBe(4000)
        ->and($items['movie']->media->totalRuntime)->toBe(120);
});
