<?php

use App\Http\Middleware\EnsureApiTokenUserIsNotBlocked;
use App\Http\Middleware\SetApiMutationSource;
use App\Http\Middleware\SetUserLocale;
use Illuminate\Support\Facades\Route;

/*
| API v1 behind a bearer token: stateless, no session, no CSRF, no Inertia.
| The routes themselves live in routes/api-v1.php (the pages reach the same
| ones through the session, see routes/web.php).
|
| The middleware order matters — everything after auth:sanctum depends on
| the token's user being resolved: the block check, the locale that titles
| come back in, and the mutation source stamped on every write.
*/

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware([
        'auth:sanctum',
        'throttle:api',
        EnsureApiTokenUserIsNotBlocked::class,
        SetUserLocale::class,
        SetApiMutationSource::class,
    ])
    ->group(base_path('routes/api-v1.php'));
