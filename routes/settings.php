<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\CalendarTokenController;
use App\Http\Controllers\Settings\ChannelsController;
use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\DangerZoneController;
use App\Http\Controllers\Settings\DetectedTimezoneController;
use App\Http\Controllers\Settings\ExportController;
use App\Http\Controllers\Settings\IdentitiesController;
use App\Http\Controllers\Settings\ImportController;
use App\Http\Controllers\Settings\IntegrationsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PreferencesController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TasksController;
use App\Http\Controllers\Settings\TrackingController;
use App\Http\Middleware\EnsurePasswordLoginIsEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:web'])->name('settings.')->group(function () {
    Route::redirect('settings', 'settings/profile')->name('index');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // A password nothing signs in with is not worth setting.
    Route::middleware(EnsurePasswordLoginIsEnabled::class)->group(function () {
        Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');
    });

    Route::get('settings/identities', [IdentitiesController::class, 'index'])->name('identities.index');
    Route::delete('settings/identities/{identity}', [IdentitiesController::class, 'destroy'])->name('identities.destroy');

    Route::get('settings/preferences', [PreferencesController::class, 'edit'])->name('preferences.edit');
    Route::patch('settings/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
    // Its own endpoint, answering 204: the client reports this in the
    // background, and going through preferences.update would send back a
    // redirect that re-renders the page under whatever the user is doing.
    Route::patch('settings/detected-timezone', DetectedTimezoneController::class)->name('timezone.detected');

    Route::get('settings/tracking', [TrackingController::class, 'edit'])->name('tracking.edit');

    Route::get('settings/channels', [ChannelsController::class, 'index'])->name('channels.index');
    Route::post('settings/channels', [ChannelsController::class, 'store'])->name('channels.store');
    Route::patch('settings/channels/{channel}', [ChannelsController::class, 'update'])->name('channels.update');
    Route::delete('settings/channels/{channel}', [ChannelsController::class, 'destroy'])->name('channels.destroy');
    // Every click reaches the service from the instance's address, and a
    // service blocks an address that sends too many.
    Route::post('settings/channels/{channel}/test', [ChannelsController::class, 'test'])
        ->middleware('throttle:channel-test')
        ->name('channels.test');

    Route::get('settings/integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
    Route::post('settings/integrations/{source}', [IntegrationsController::class, 'store'])->name('integrations.store');
    Route::patch('settings/integrations/endpoints/{endpoint}', [IntegrationsController::class, 'update'])->name('integrations.update');
    Route::post('settings/integrations/endpoints/{endpoint}/rotate', [IntegrationsController::class, 'rotate'])->name('integrations.rotate');
    // The dry run reaches out to the metadata provider, so it is not a
    // button worth leaning on.
    Route::post('settings/integrations/endpoints/{endpoint}/test', [IntegrationsController::class, 'test'])
        ->middleware('throttle:integration-test')
        ->name('integrations.test');
    Route::delete('settings/integrations/endpoints/{endpoint}', [IntegrationsController::class, 'destroy'])->name('integrations.destroy');

    Route::get('settings/import', [ImportController::class, 'index'])->name('import.index');
    Route::post('settings/import', [ImportController::class, 'store'])->middleware('throttle:library-heavy')->name('import.store');
    // Analysing a credentials source reads the user's profile at the
    // service, so it shares the provider budget.
    Route::post('settings/import/{source}/analyze', [ImportController::class, 'analyze'])
        ->middleware('throttle:providers')
        ->name('import.analyze');

    // Connections imports read through. The card polls the device flow
    // every few seconds, so these share a budget of their own; the pace the
    // service asks for is kept server-side in the controller.
    Route::middleware('throttle:connections')->group(function () {
        Route::post('settings/connections/{service}/device', [ConnectionController::class, 'beginDevice'])->name('connections.device.begin');
        Route::post('settings/connections/{service}/device/poll', [ConnectionController::class, 'pollDevice'])->name('connections.device.poll');
        Route::delete('settings/connections/{service}/device', [ConnectionController::class, 'cancelDevice'])->name('connections.device.cancel');
        Route::post('settings/connections/{service}/verify', [ConnectionController::class, 'verify'])->name('connections.verify');
        Route::post('settings/connections/{service}', [ConnectionController::class, 'connect'])->name('connections.connect');
    });
    Route::patch('settings/connections/{connection}', [ConnectionController::class, 'update'])->name('connections.update');
    Route::delete('settings/connections/{connection}', [ConnectionController::class, 'destroy'])->name('connections.destroy');

    Route::get('settings/export', [ExportController::class, 'index'])->name('export.index');
    Route::get('settings/export/{format}', [ExportController::class, 'download'])->middleware('throttle:library-heavy')->name('export.download');
    Route::post('settings/calendar-token', CalendarTokenController::class)->name('calendar-token.update');

    Route::get('settings/api', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('settings/api/tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('settings/api/tokens/{token}', [ApiTokenController::class, 'destroy'])->whereNumber('token')->name('api-tokens.destroy');

    Route::get('settings/tasks', [TasksController::class, 'index'])->name('tasks.index');
    Route::get('settings/tasks/runs', [TasksController::class, 'runs'])->name('tasks.runs');
    Route::post('settings/tasks/run', [TasksController::class, 'run'])->middleware('throttle:library-heavy')->name('tasks.run');
    Route::delete('settings/tasks', [TasksController::class, 'destroy'])->name('tasks.destroy');

    Route::get('settings/danger', [DangerZoneController::class, 'index'])->name('danger.index');
    Route::delete('settings/data', [DangerZoneController::class, 'clearData'])->name('data.destroy');
});
