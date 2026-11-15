<?php

use App\Models\User;

test('the shared title page serves only a root with nothing beneath it', function (string $path) {
    $this->actingAs(User::factory()->create())->get($path)->assertNotFound();
})->with([
    'a season' => ['/season/5'],
    'an episode' => ['/episode/5'],
    'a type nobody runs' => ['/podcast/5'],
]);

test('a two-segment path an earlier route claims is not a title', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get("/user/{$user->username}")->assertOk();
});
