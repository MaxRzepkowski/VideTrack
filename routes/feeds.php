<?php

use App\Http\Controllers\CalendarFeedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Subscription feeds
|--------------------------------------------------------------------------
|
| Outside the `web` group, like the webhooks: a calendar app polling every
| few hours sends no cookie, so the web chain would start a fresh session
| (a database row) and run the Inertia share on every poll. The token in the
| path stands in for authentication.
|
*/

Route::where(['token' => '[A-Za-z0-9]+'])->group(function () {
    Route::get('calendar/{token}.ics', [CalendarFeedController::class, 'ics'])->name('calendar.feed');
    Route::get('calendar/{token}.rss', [CalendarFeedController::class, 'rss'])->name('calendar.rss');
});
