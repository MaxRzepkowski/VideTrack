<?php

use App\Models\User;
use App\Tracking\Surfaces\FeedScope;
use Inertia\Testing\AssertableInertia as Assert;

test('a new calendar address retires the old one for both feeds at once', function () {
    $user = User::factory()->create();
    $old = $user->calendarToken();

    $this->actingAs($user)
        ->from(route('settings.export.index'))
        ->post(route('settings.calendar-token.update'))
        ->assertRedirect(route('settings.export.index'));

    $new = $user->fresh()->calendarToken();

    expect($new)->not->toBe($old);

    $this->get(route('calendar.feed', ['token' => $old]))->assertNotFound();
    $this->get(route('calendar.rss', ['token' => $old]))->assertNotFound();
    $this->get(route('calendar.feed', ['token' => $new]))->assertOk();
    $this->get(route('calendar.rss', ['token' => $new]))->assertOk();
});

test('only a signed-in user changes their calendar address', function () {
    $this->post(route('settings.calendar-token.update'))->assertRedirect(route('login'));
});

test('the export page hands out both feed addresses with the scope each carries by default', function () {
    $user = User::factory()->create();
    $token = $user->calendarToken();

    $this->actingAs($user)->get(route('settings.export.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendarFeed', ['url' => route('calendar.feed', ['token' => $token]), 'defaultScope' => FeedScope::All->value])
            ->where('rssFeed', ['url' => route('calendar.rss', ['token' => $token]), 'defaultScope' => FeedScope::Premieres->value]));
});
