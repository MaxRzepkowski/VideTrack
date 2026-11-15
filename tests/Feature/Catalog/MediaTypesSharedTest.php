<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// The front's test factory reads the same file, so its registry is the server's.
test('every page shares the registry as the front tests know it, every module on', function () {
    enableGamesModule();
    enableBooksModule();
    enableMangaModule();
    enableComicsModule();

    $this->actingAs(User::factory()->create())
        ->get(route('about'))
        ->assertInertia(fn (Assert $page) => $page->where('mediaTypes', jsonFixture('media-types/all-modules')));
});
