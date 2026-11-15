<?php

use Illuminate\Support\Facades\Route;

test('a missing page renders on the app\'s own layout, with a way home', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('404')
        ->assertSee('Not Found')
        ->assertSee('Not Found – VideTrack</title>', escape: false)
        ->assertSee('href="'.url('/').'"', escape: false);
});

test('every framework error code shares the layout', function (int $status, string $message) {
    Route::get('/_error-page-test', fn () => abort($status));

    $this->get('/_error-page-test')
        ->assertStatus($status)
        ->assertSee((string) $status)
        ->assertSee("{$message} – VideTrack</title>", escape: false);
})->with([
    [403, 'Forbidden'],
    [419, 'Page Expired'],
    [429, 'Too Many Requests'],
    [500, 'Server Error'],
    [503, 'Service Unavailable'],
]);

test('a code without a view of its own falls back to its status text', function (int $status, string $message) {
    Route::get('/_error-page-test', fn () => abort($status));

    $this->get('/_error-page-test')
        ->assertStatus($status)
        ->assertSee("{$message} – VideTrack</title>", escape: false);
})->with([
    [405, 'Method Not Allowed'],
    [502, 'Bad Gateway'],
]);

test('error pages speak the instance default language', function () {
    app()->setLocale('pl');

    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('Strona główna');
});
