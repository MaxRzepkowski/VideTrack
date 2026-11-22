<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CountdownController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Lists\ListController;
use App\Http\Controllers\Media\ArchivedTitlesController;
use App\Http\Controllers\Media\CollectionController;
use App\Http\Controllers\Media\CreatorCreditsController;
use App\Http\Controllers\Media\CreatorPageController;
use App\Http\Controllers\Media\DiscoverController;
use App\Http\Controllers\Media\EpisodeDetailsController;
use App\Http\Controllers\Media\EventPageController;
use App\Http\Controllers\Media\FranchiseController;
use App\Http\Controllers\Media\MovieDetailsController;
use App\Http\Controllers\Media\PaletteController;
use App\Http\Controllers\Media\PlatformController;
use App\Http\Controllers\Media\RefreshController;
use App\Http\Controllers\Media\SearchController;
use App\Http\Controllers\Media\SeasonDetailsController;
use App\Http\Controllers\Media\ShowDetailsController;
use App\Http\Controllers\Media\TitleDetailsController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SuggestionsController;
use App\Http\Controllers\Users\UserFavoritesController;
use App\Http\Controllers\Users\UserFollowingController;
use App\Http\Controllers\Users\UserHistoryController;
use App\Http\Controllers\Users\UserListsController;
use App\Http\Controllers\Users\UserProfileController;
use App\Http\Controllers\Users\UserProgressController;
use App\Http\Controllers\Users\UserRatingsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Two budgets (per user): `web` for everything a signed-in person clicks,
// and the narrower `providers` on the routes that may call a metadata
// provider — a burst of checked-off episodes must not eat the budget of
// the pages that ask one.
Route::middleware(['auth', 'throttle:web'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('about', AboutController::class)->name('about');
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');
    Route::get('countdowns', [CountdownController::class, 'index'])->name('countdowns');
    Route::get('statistics', StatisticsController::class)->name('statistics');
    Route::get('suggestions', [SuggestionsController::class, 'index'])->name('suggestions.index');
    Route::get('suggestions/{shelf}', [SuggestionsController::class, 'show'])
        ->where('shelf', '[a-z-]+')->name('suggestions.show');
    Route::get('suggestions/{shelf}/{subject}', [SuggestionsController::class, 'show'])
        ->where('shelf', '[a-z-]+')->where('subject', '[A-Za-z0-9:_-]+')->name('suggestions.subject');
    // Not in any menu: reached from the "no longer available" notification
    // or by its address.
    Route::get('archived', ArchivedTitlesController::class)->name('library.archived');

    Route::middleware('throttle:providers')->group(function () {
        Route::get('search', SearchController::class)->name('search');
        Route::get('palette', PaletteController::class)->name('palette');
        Route::get('discover', DiscoverController::class)->name('discover');
        Route::get('movie/{ref}', MovieDetailsController::class)
            ->whereNumber('ref')->name('movie.details');
        Route::get('show/{ref}', ShowDetailsController::class)
            ->whereNumber('ref')->name('show.details');
        Route::get('show/{ref}/season/{number}', SeasonDetailsController::class)
            ->whereNumber('ref')->whereNumber('number')->name('season.details');
        Route::get('show/{ref}/season/{number}/episode/{episode}', EpisodeDetailsController::class)
            ->whereNumber('ref')->whereNumber('number')->whereNumber('episode')->name('episode.details');
        Route::get('person/{provider}/{ref}', CreatorPageController::class)
            ->defaults('kind', 'person')->whereAlphaNumeric('provider')->whereNumber('ref')->name('person.details');
        Route::get('company/{provider}/{ref}', CreatorPageController::class)
            ->defaults('kind', 'company')->whereAlphaNumeric('provider')->whereNumber('ref')->name('company.details');
        Route::get('company/{provider}/{ref}/credits', CreatorCreditsController::class)
            ->defaults('kind', 'company')->whereAlphaNumeric('provider')->whereNumber('ref')->name('company.credits');
        Route::get('network/{provider}/{ref}', CreatorPageController::class)
            ->defaults('kind', 'network')->whereAlphaNumeric('provider')->whereNumber('ref')->name('network.details');
        Route::get('network/{provider}/{ref}/credits', CreatorCreditsController::class)
            ->defaults('kind', 'network')->whereAlphaNumeric('provider')->whereNumber('ref')->name('network.credits');
        Route::get('collection/{provider}/{ref}', CollectionController::class)
            ->whereAlphaNumeric('provider')->where('ref', '[A-Za-z0-9:_-]+')->name('collections.show');
        Route::get('franchise/{provider}/{ref}', FranchiseController::class)
            ->whereAlphaNumeric('provider')->whereNumber('ref')->name('franchises.show');
        Route::get('platform/{provider}/{ref}', PlatformController::class)
            ->whereAlphaNumeric('provider')->whereNumber('ref')->name('platforms.show');
        Route::get('event/{provider}/{ref}', EventPageController::class)
            ->whereAlphaNumeric('provider')->whereNumber('ref')->name('events.show');

        // On-demand refresh from an entity page (cache bypassed, synchronous).
        Route::post('media/refresh', [RefreshController::class, 'media'])->name('media.refresh');
        Route::post('collection/{provider}/{ref}/refresh', [RefreshController::class, 'collection'])
            ->whereAlphaNumeric('provider')->where('ref', '[A-Za-z0-9:_-]+')->name('collections.refresh');
        Route::post('person/{provider}/{ref}/refresh', [RefreshController::class, 'creator'])
            ->defaults('kind', 'person')->whereAlphaNumeric('provider')->whereNumber('ref')->name('person.refresh');
        Route::post('company/{provider}/{ref}/refresh', [RefreshController::class, 'creator'])
            ->defaults('kind', 'company')->whereAlphaNumeric('provider')->whereNumber('ref')->name('company.refresh');
        Route::post('network/{provider}/{ref}/refresh', [RefreshController::class, 'creator'])
            ->defaults('kind', 'network')->whereAlphaNumeric('provider')->whereNumber('ref')->name('network.refresh');
    });

    // The bell's shortcut; the page itself lives under the profile.
    Route::get('following', [UserFollowingController::class, 'mine'])->name('follows.index');
    Route::get('notifications', [NotificationsController::class, 'index'])->name('notifications.index');
    Route::get('notifications/feed', [NotificationsController::class, 'feed'])->name('notifications.feed');
    Route::delete('notifications/all', [NotificationsController::class, 'destroyAll'])->name('notifications.destroy-all');
    Route::whereUuid('notification')->group(function () {
        Route::get('notifications/{notification}', [NotificationsController::class, 'show'])->name('notifications.show');
        Route::delete('notifications/{notification}', [NotificationsController::class, 'destroy'])->name('notifications.destroy');
    });

    Route::get('lists', [ListController::class, 'index'])->name('lists.index');
    // The menu's shortcut to the signed-in user's Backlog, a list with an id of its own.
    Route::get('backlog', [ListController::class, 'backlog'])->name('lists.backlog');

    // One user view, one URL and one controller per panel: the profile is the
    // root, everything else hangs off it. Nothing here switches on a tab —
    // each panel states for itself what it reads and how.
    Route::get('user/{user:username}', UserProfileController::class)->name('users.show');
    Route::get('user/{user:username}/history', UserHistoryController::class)->name('users.history');
    Route::get('user/{user:username}/ratings', UserRatingsController::class)->name('users.ratings');
    Route::get('user/{user:username}/favorites', UserFavoritesController::class)->name('users.favorites');
    Route::get('user/{user:username}/progress', UserProgressController::class)->name('users.progress');
    Route::get('user/{user:username}/following', UserFollowingController::class)->name('users.following');
    Route::get('user/{user:username}/lists', UserListsController::class)->name('users.lists');
    // A list lives under its owner: another user's list here is not there at
    // all. A collection has no owner and its own address (`collections.show`).
    Route::get('user/{user:username}/lists/{list}', [ListController::class, 'show'])
        ->whereNumber('list')->scopeBindings()->name('users.lists.show');
});

// API v1 for the pages themselves: the same routes a token reaches under
// api/v1, here behind the browser session and its CSRF token. Writes count as
// the UI's (the mutation context's default).
Route::prefix('ui/v1')
    ->name('ui.')
    ->middleware(['auth', 'throttle:web'])
    ->group(base_path('routes/api-v1.php'));

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

// Every other title page: a type with nothing beneath it (a book, a game, a
// manga, a comic…) under its own name, so a new type needs no route. Last of
// all: a two-segment path an earlier route claims is no title.
Route::middleware(['auth', 'throttle:web', 'throttle:providers'])->group(function () {
    Route::get('{type}/{ref}', TitleDetailsController::class)
        ->where('type', '[a-z]+')->whereNumber('ref')->name('title.details');
});
