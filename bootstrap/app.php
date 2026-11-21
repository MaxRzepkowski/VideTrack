<?php

use App\Accounts\Identity\IdentityRefused;
use App\Catalog\Creators\UnknownCreator;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Exceptions\LibraryRefusal;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureMcpIsEnabled;
use App\Http\Middleware\EnsureUserIsNotBlocked;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTokenAbility;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetUserLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // In place of `health: '/up'`, whose page cannot be restyled.
            // Outside `web` like the framework's: a health probe carries no
            // session and must not start one.
            Route::get('up', HealthController::class);

            // Incoming webhooks get a group of their own: no session, no CSRF,
            // no Inertia share — a media server posting JSON has none of those,
            // and excluding CSRF alone would still run the whole web chain.
            Route::middleware('throttle:webhooks')
                ->group(__DIR__.'/../routes/webhooks.php');

            // Subscription feeds polled by calendar apps: same reasoning.
            Route::middleware('throttle:ical')
                ->group(__DIR__.'/../routes/feeds.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Behind a reverse proxy (the documented self-host setup): trust
        // forwarded headers so client IP, scheme and host are correct — the
        // login throttle key and Secure-cookie/HTTPS detection depend on it.
        // Which senders count as the proxy: config/trustedproxy.php.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // AuthenticateSession ends a session once the account's password hash
        // changes (reset from the console or by e-mail), on every session
        // driver; deleting session rows only works on the database driver.
        // Every response, not only pages: the API, webhooks and feeds too.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureUserIsNotBlocked::class,
            SetUserLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // A closed MCP server answers 404 before the token is read: without
        // this the framework moves authentication ahead of it, and the 401
        // would tell a stranger the server exists.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureMcpIsEnabled::class);

        // Token abilities (read / write) gate whole route groups of API v1
        // (routes/api-v1.php); the browser session passes them as the account.
        $middleware->alias([
            'abilities' => RequireTokenAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // An API client gets JSON for every failure, never an HTML error
        // page — including the framework's own 404s and 405s.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        // A failed validation flashes the input into the session; a
        // notification channel's settings, a service's sign-in values and an
        // import's credentials (an API key typed for one run) stay out.
        $exceptions->dontFlash(['config', 'values', 'credentials']);

        // A rule of the library refused the change: the same validation error
        // on the web (back with errors) and in the API (422).
        $exceptions->map(fn (LibraryRefusal $e) => ValidationException::withMessages([$e->field() => $e->reason()]));

        // A rule of the account refused a change to how it signs in: the
        // same, on the field the settings page reads.
        $exceptions->map(fn (IdentityRefused $e) => ValidationException::withMessages([IdentityRefused::FIELD => $e->reason()]));

        // Missing upstream item → regular 404. Nothing serves this creator
        // here — a link to an unknown provider — is a 404 too, not a failure
        // worth a log entry.
        $exceptions->map(ItemNotFoundException::class, fn (ItemNotFoundException $e) => new NotFoundHttpException(previous: $e));
        $exceptions->dontReport(UnknownCreator::class);
        $exceptions->map(UnknownCreator::class, fn (UnknownCreator $e) => new NotFoundHttpException(previous: $e));

        // Any other provider failure → 503, so the UI shows a "could not
        // load" state instead of a crash. Reported first, as a warning: a
        // mapped exception would skip the log. Its message stays in the log,
        // never in the response.
        $exceptions->level(ProviderException::class, LogLevel::WARNING);
        $exceptions->level(ProviderRejected::class, LogLevel::WARNING);
        $exceptions->render(fn (ProviderException|ProviderRejected $e) => abort(503));
    })->create();
