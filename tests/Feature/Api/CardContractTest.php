<?php

use App\Actions\Library\SyncListMembership;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Arr;

// One card, two ways out: what a page receives and what `ui/v1` answers for
// the same title carry the same card fields; the API only adds its own
// (image URLs, the state).

test('a list position carries the same card on the list page and in the API', function () {
    $user = User::factory()->create();
    $episode = episodeOf(makeShowTree([2 => ['2026-01-01']]), 2, 1);
    $list = MediaList::factory()->create(['user_id' => $user->id]);
    app(SyncListMembership::class)->execute($user, $episode, [$list->id]);

    $onPage = $this->actingAs($user)->get(listPage($list))
        ->assertOk()
        ->viewData('page')['props']['items'][0]->media;
    $page = json_decode((string) json_encode($onPage), true);
    $api = $this->getJson(route('ui.lists.show', ['list' => $list->id]))
        ->assertOk()
        ->json('data.items.0.media');

    expect($page['extra']['seasonNumber'])->toBe(2)
        ->and(Arr::only($api, array_keys($page)))->toEqual($page);
});
