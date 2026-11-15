<?php

use App\Http\Controllers\WebhookIngestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Incoming webhooks
|--------------------------------------------------------------------------
|
| Registered outside the `web` group on purpose. CSRF, sessions, cookies and
| the Inertia share belong to that group, and a media server posting JSON has
| none of them — excluding CSRF alone would still drag the whole chain along.
| These routes carry a throttle and nothing else.
|
| The token in the path IS the credential, exactly like the calendar feed's.
| Nothing readable lives on this route: it only ever takes data in.
|
*/

Route::post('webhooks/{source}/{token}', WebhookIngestController::class)
    ->whereAlphaNumeric('source')
    ->where('token', '[A-Za-z0-9]+')
    ->name('webhooks.ingest');
