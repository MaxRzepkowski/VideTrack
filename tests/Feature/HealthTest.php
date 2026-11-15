<?php

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;

test('the health page answers 200 on the app\'s own layout', function () {
    $this->get('/up')
        ->assertOk()
        ->assertSee('VideTrack is up')
        ->assertSee('<title>VideTrack</title>', escape: false)
        // Self-contained: no framework CDN, and no session started for a probe.
        ->assertDontSee('cdn.jsdelivr.net', escape: false)
        ->assertCookieMissing(config('session.cookie'));
});

test('a failing health listener turns the page into a 500', function () {
    config(['app.debug' => false]);

    Event::listen(DiagnosingHealth::class, function () {
        throw new RuntimeException('database gone');
    });

    $this->get('/up')
        ->assertStatus(500)
        ->assertSee('VideTrack is experiencing problems')
        ->assertDontSee('database gone');
});

test('the health page speaks the instance default language', function () {
    app()->setLocale('pl');

    $this->get('/up')->assertOk()->assertSee('VideTrack działa');
});
