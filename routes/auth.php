<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\IdentityController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Middleware\EnsurePasswordLoginIsEnabled;
use App\Http\Middleware\EnsureRegistrationIsEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    // Everything a password does, closed where identity providers are the only way in.
    Route::middleware(EnsurePasswordLoginIsEnabled::class)->group(function () {
        Route::middleware(EnsureRegistrationIsEnabled::class)->group(function () {
            Route::get('register', [RegisteredUserController::class, 'create'])
                ->name('register');

            Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:account-forms');
        });

        Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');

        Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
            ->name('password.request');

        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:account-forms')
            ->name('password.email');

        Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');

        Route::post('reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:account-forms')
            ->name('password.store');
    });
});

// Outside the guest group: someone signed in takes the same trip to connect
// a provider to their account.
Route::middleware('throttle:identity-sign-in')->group(function () {
    Route::get('auth/{provider}/redirect', [IdentityController::class, 'redirect'])->name('identities.redirect');
    Route::get('auth/{provider}/callback', [IdentityController::class, 'callback'])->name('identities.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
