<?php

use App\Accounts\TokenAbility;
use App\Catalog\MediaRef;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CreatorFollowController;
use App\Http\Controllers\Api\V1\CreatorListsController;
use App\Http\Controllers\Api\V1\EventVisibilityController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\ListController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MediaFollowController;
use App\Http\Controllers\Api\V1\MediaStateController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlayController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\WatchCycleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| One surface, two doors: `api/v1` for scripts and other clients (a bearer
| token), and `ui/v1` for VideTrack's own pages (the
| browser session, CSRF). The routes, rules and answers are the same — the
| web app is one more client of this API, not a twin of it.
|
| The contract, for every route:
| - answers are camelCase, like the props the pages receive; request fields
|   are snake_case, like the forms the pages send;
| - the answer is always under `data` — a resource or a list of them —
|   with `meta` beside it where there is something to count or page;
| - a position carries the user's state as its `state` field; a write that
|   changes a position answers with it and adds `states`, every state the
|   write touched ("type:ref" => state: the position and the containers
|   above it), so a client holding any of them is current without asking;
| - timestamps are ISO 8601 instants, dates are plain YYYY-MM-DD.
|
| Refs can contain colons ("1399:1:1"): a legal path character (RFC 3986),
| so they travel in the path like any other resource id.
|
*/

Route::middleware('abilities:'.TokenAbility::Read->value)->group(function () {
    Route::get('me', MeController::class)->name('me');
    // The two reads that fan out to a provider share the pages' provider budget.
    Route::get('search', SearchController::class)->middleware('throttle:providers')->name('search');
    Route::get('library', LibraryController::class)->name('library');
    Route::get('calendar', CalendarController::class)->name('calendar');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');

    Route::get('lists', [ListController::class, 'index'])->name('lists.index');
    Route::get('lists/{list}', [ListController::class, 'show'])->name('lists.show');

    Route::where(['ref' => MediaRef::PATTERN])->group(function () {
        Route::get('media/{type}/{ref}', [MediaController::class, 'show'])->middleware('throttle:providers')->name('media.show');
        Route::get('media/{type}/{ref}/plays', [PlayController::class, 'index'])->name('media.plays.index');
        Route::get('media/{type}/{ref}/cycles', [WatchCycleController::class, 'index'])->name('media.cycles.index');
    });

    Route::where(['provider' => '[a-zA-Z0-9]+', 'ref' => '[0-9]+'])->group(function () {
        Route::get('people/{provider}/{ref}/lists', [CreatorListsController::class, 'show'])
            ->defaults('kind', 'person')->name('people.lists.show');
        Route::get('companies/{provider}/{ref}/lists', [CreatorListsController::class, 'show'])
            ->defaults('kind', 'company')->name('companies.lists.show');
        Route::get('networks/{provider}/{ref}/lists', [CreatorListsController::class, 'show'])
            ->defaults('kind', 'network')->name('networks.lists.show');
    });
});

Route::middleware('abilities:'.TokenAbility::Write->value)->group(function () {
    Route::where(['ref' => MediaRef::PATTERN])->group(function () {
        Route::post('media/{type}/{ref}/plays', [PlayController::class, 'store'])->name('media.plays.store');
        Route::delete('media/{type}/{ref}/plays', [PlayController::class, 'destroy'])->name('media.plays.destroy');

        Route::post('media/{type}/{ref}/cycles', [WatchCycleController::class, 'store'])->name('media.cycles.store');
        Route::delete('media/{type}/{ref}/cycles', [WatchCycleController::class, 'destroy'])->name('media.cycles.destroy');

        Route::put('media/{type}/{ref}/rating', [MediaStateController::class, 'rate'])->name('media.rating.update');
        Route::delete('media/{type}/{ref}/rating', [MediaStateController::class, 'removeRating'])->name('media.rating.destroy');
        Route::put('media/{type}/{ref}/status', [MediaStateController::class, 'status'])->name('media.status.update');
        Route::put('media/{type}/{ref}/progress', [MediaStateController::class, 'progress'])->name('media.progress.update');
        Route::put('media/{type}/{ref}/favorite', [MediaStateController::class, 'favorite'])->name('media.favorite.update');
        Route::put('media/{type}/{ref}/lists', [MediaStateController::class, 'lists'])->name('media.lists.update');
        Route::put('media/{type}/{ref}/follow', [MediaFollowController::class, 'update'])->name('media.follow.update');
    });

    // One history entry, addressed by its own id.
    Route::patch('plays/{play}', [PlayController::class, 'update'])->name('plays.update');
    Route::delete('plays/{play}', [PlayController::class, 'destroyOne'])->name('plays.destroy');

    Route::where(['provider' => '[a-zA-Z0-9]+', 'ref' => '[0-9]+'])->group(function () {
        Route::put('people/{provider}/{ref}/follow', [CreatorFollowController::class, 'update'])
            ->defaults('kind', 'person')->name('people.follow.update');
        Route::put('companies/{provider}/{ref}/follow', [CreatorFollowController::class, 'update'])
            ->defaults('kind', 'company')->name('companies.follow.update');
        Route::put('networks/{provider}/{ref}/follow', [CreatorFollowController::class, 'update'])
            ->defaults('kind', 'network')->name('networks.follow.update');

        Route::put('people/{provider}/{ref}/lists', [CreatorListsController::class, 'update'])
            ->defaults('kind', 'person')->name('people.lists.update');
        Route::put('companies/{provider}/{ref}/lists', [CreatorListsController::class, 'update'])
            ->defaults('kind', 'company')->name('companies.lists.update');
        Route::put('networks/{provider}/{ref}/lists', [CreatorListsController::class, 'update'])
            ->defaults('kind', 'network')->name('networks.lists.update');
    });

    // Collection refs are composite on some providers ("series:42").
    Route::put('collections/{provider}/{ref}/follow', [CreatorFollowController::class, 'collection'])
        ->whereAlphaNumeric('provider')->where('ref', '[A-Za-z0-9:_-]+')->name('collections.follow.update');

    Route::where(['provider' => '[a-zA-Z0-9]+', 'ref' => '[0-9]+'])->group(function () {
        Route::put('events/{provider}/{ref}/hidden', [EventVisibilityController::class, 'hide'])->name('events.hidden.update');
        Route::delete('events/{provider}/{ref}/hidden', [EventVisibilityController::class, 'show'])->name('events.hidden.destroy');
    });

    Route::post('lists', [ListController::class, 'store'])->name('lists.store');
    Route::put('lists/order', [ListController::class, 'reorder'])->name('lists.reorder');
    Route::patch('lists/{list}', [ListController::class, 'update'])->name('lists.update');
    Route::post('lists/{list}/duplicate', [ListController::class, 'duplicate'])->name('lists.duplicate');
    Route::put('lists/{list}/items/order', [ListController::class, 'reorderItems'])->name('lists.items.reorder');
    Route::delete('lists/{list}', [ListController::class, 'destroy'])->name('lists.destroy');
    Route::delete('lists/{list}/items/{item}', [ListController::class, 'removeItem'])
        ->scopeBindings()->name('lists.items.destroy');

    Route::patch('notifications/{notification}', [NotificationController::class, 'read'])
        ->whereUuid('notification')->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
});
