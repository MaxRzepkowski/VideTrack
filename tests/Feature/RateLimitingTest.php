<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('everything a signed-in person clicks shares one budget per user', function () {
    // The throttle middleware advertises its ceiling in the response headers.
    $this->actingAs(User::factory()->create())
        ->get('/about')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', config('tracker.rate_limits.web'));
});

test('the routes that call a provider have a narrower budget of their own', function () {
    $search = Route::getRoutes()->getByName('search');
    $about = Route::getRoutes()->getByName('about');

    expect($search?->gatherMiddleware())->toContain('throttle:web', 'throttle:providers')
        ->and($about?->gatherMiddleware())->not->toContain('throttle:providers');
});

test('the api reads that call a provider take the provider budget on both doors', function (string $name) {
    expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:providers');
})->with(['ui.search', 'ui.media.show', 'api.v1.search', 'api.v1.media.show']);

test('the pages write through the api on the web budget', function () {
    expect(Route::getRoutes()->getByName('ui.media.rating.update')?->gatherMiddleware())
        ->toContain('throttle:web')
        ->not->toContain('throttle:api');
});

test('the public iCal feed is rate limited per subscription token', function () {
    $token = User::factory()->create()->calendarToken();

    $this->get("/calendar/{$token}.ics")
        ->assertHeader('X-RateLimit-Limit', config('tracker.rate_limits.ical_per_feed'));
});

test('the password reset form refuses a burst from one address', function () {
    $this->freezeTime();

    $limit = config()->integer('tracker.rate_limits.account_forms');

    foreach (range(1, $limit) as $attempt) {
        $this->post('/forgot-password', ['email' => "someone{$attempt}@example.com"])->assertRedirect();
    }

    $this->post('/forgot-password', ['email' => 'one-too-many@example.com'])->assertTooManyRequests();
});

test('export refuses a loop', function () {
    $this->freezeTime();
    $user = User::factory()->create();

    foreach (range(1, config()->integer('tracker.rate_limits.library_heavy')) as $attempt) {
        $this->actingAs($user)->get('/settings/export/videtrack')->assertOk();
    }

    $this->actingAs($user)->get('/settings/export/videtrack')->assertTooManyRequests();
});

test('polling the iCal feed starts no session', function () {
    $token = User::factory()->create()->calendarToken();

    $this->get("/calendar/{$token}.ics")
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'));
});
