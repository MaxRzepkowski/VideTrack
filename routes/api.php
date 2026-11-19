<?php

use App\Http\Mcp\LibraryServer;
use App\Http\Middleware\EnsureApiTokenUserIsNotBlocked;
use App\Http\Middleware\EnsureMcpIsEnabled;
use App\Http\Middleware\SetApiMutationSource;
use App\Http\Middleware\SetUserLocale;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
| Behind a bearer token: stateless, no session, no CSRF, no Inertia. Two
| doors share it — API v1, whose routes live in routes/api-v1.php (the pages
| reach the same ones through the session, see routes/web.php), and the MCP
| server for AI assistants, whose tools call v1's routes.
|
| The middleware order matters — everything after auth:sanctum depends on
| the token's user being resolved: the block check, the locale that titles
| come back in, and the mutation source stamped on every write.
*/

/** The token's door, limited by the named limiter given. */
$tokenDoor = fn (string $limiter): array => [
    'auth:sanctum',
    "throttle:{$limiter}",
    EnsureApiTokenUserIsNotBlocked::class,
    SetUserLocale::class,
    SetApiMutationSource::class,
];

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware($tokenDoor('api'))
    ->group(base_path('routes/api-v1.php'));

// Closed before the token is even read, so a closed server does not say it
// exists. Its own limiter counts the calls that never reach v1 (the
// handshake, the list of tools); a tool's calls to v1 count there as well.
Route::middleware([EnsureMcpIsEnabled::class, ...$tokenDoor('mcp')])->group(function () {
    Mcp::web('mcp', LibraryServer::class)->name('api.mcp');
});
