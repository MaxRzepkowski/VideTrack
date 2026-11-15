<?php

use App\Enums\MediaUserStatus;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;

// An instance can switch a module off (phpunit.xml leaves IGDB without keys,
// so games are not served here) while libraries still hold its titles. Those
// titles wait for the module to return; the pages around them go on.

test('the pages that read a library go on past the titles of a module switched off', function (string $route) {
    $user = User::factory()->create();
    $movie = MediaItem::factory()->movie()->create(['runtime' => 120]);

    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => $movie->id]);
    Play::factory()->create(['user_id' => $user->id, 'media_item_id' => MediaItem::factory()->game()->create()->id]);
    MediaUserState::factory()->create(['user_id' => $user->id, 'status' => MediaUserStatus::OnHold]);
    projectLibrary($user);

    $this->actingAs($user)
        ->get(route($route, ['user' => $user->username]))
        ->assertOk();
})->with(['dashboard', 'users.progress', 'users.history']);
