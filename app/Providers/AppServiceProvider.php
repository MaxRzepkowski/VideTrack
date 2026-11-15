<?php

namespace App\Providers;

use App\Catalog\ProviderCacheContext;
use App\Library\LibraryProjector;
use App\Library\MutationContext;
use App\Models\User;
use App\Policies\PersonalAccessTokenPolicy;
use App\Support\PublicUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Shared, request-scoped flag the cache decorator reads to bypass the
        // response cache when a manual refresh needs genuinely fresh data.
        $this->app->singleton(ProviderCacheContext::class);

        // Where the current mutation came from. Scoped, not singleton: the
        // queue worker resets scoped instances between jobs, so one import
        // cannot stamp its source onto the next job's mutations.
        $this->app->scoped(MutationContext::class);

        // One per request or job: the batch an import opens has to be the
        // one its writes' listener sees, and the next job starts without it.
        $this->app->scoped(LibraryProjector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A relation read inside a loop is an N+1; outside production it
        // throws, so tests and dev catch it before a big library does.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Everything a signed-in person clicks: pages, and the writes the
        // pages send through ui/v1. A ceiling for a runaway tab, not a pace
        // a person reaches. 'auth' runs before this limiter; the IP only
        // stands in for the type.
        RateLimiter::for('web', fn (Request $request): Limit => Limit::perMinute(self::limit('web'))
            ->by((string) ($request->user()->id ?? $request->ip())));

        // Search / discover / detail pages fan out to the upstream provider;
        // without a ceiling of their own a session could burn the provider's
        // rate limit and cost.
        RateLimiter::for('providers', fn (Request $request): Limit => Limit::perMinute(self::limit('provider_pages'))
            ->by((string) ($request->user()->id ?? $request->ip())));

        // The public iCal feed: per subscription, and per IP so that guessing
        // tokens does not get a fresh budget with every guess.
        RateLimiter::for('ical', fn (Request $request): array => [
            Limit::perMinute(self::limit('ical_per_feed'))->by('t:'.(string) $request->route('token')),
            Limit::perMinute(self::limit('ical_per_ip'))->by('ip:'.(string) $request->ip()),
        ]);

        // Ids in paths are numbers; anything else is a 404 at the router
        // instead of a lookup.
        Route::patterns([
            'list' => '[0-9]+',
            'item' => '[0-9]+',
            'play' => '[0-9]+',
            'endpoint' => '[0-9]+',
            'connection' => '[0-9]+',
        ]);

        // Scripts are allowed to be busier than a person clicking, but a
        // runaway loop still must not hammer the instance (or, through it,
        // the upstream providers). 'auth:sanctum' runs before this limiter,
        // so the token's user is normally present; the IP is the fallback.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(self::limit('api'))
            ->by((string) (Auth::id() ?? $request->ip())));

        // Two ceilings, because the two abuses differ: one endpoint's token
        // leaking (per token) and somebody spraying the instance (per IP).
        RateLimiter::for('webhooks', fn (Request $request): array => [
            Limit::perMinute(self::limit('webhooks_per_endpoint'))->by('t:'.(string) $request->route('token')),
            Limit::perMinute(self::limit('webhooks_per_ip'))->by('ip:'.(string) $request->ip()),
        ]);

        // Forms an anonymous visitor can submit in a loop: each attempt creates
        // an account or sends an e-mail. Per IP; the login form has a limiter
        // of its own below.
        RateLimiter::for('account-forms', fn (Request $request): Limit => Limit::perMinute(self::limit('account_forms'))
            ->by((string) $request->ip()));

        // Settings actions that read or write a whole library (import upload,
        // export, running a task). Generous for a person, a wall for a loop.
        RateLimiter::for('library-heavy', fn (Request $request): Limit => Limit::perMinute(self::limit('library_heavy'))
            ->by((string) $request->user()?->id));

        // Settings buttons that reach outside the instance, per user.
        foreach (['discord-test' => 'discord_test', 'integration-test' => 'integration_test', 'connections' => 'connections'] as $limiter => $limit) {
            RateLimiter::for($limiter, fn (Request $request): Limit => Limit::perMinute(self::limit($limit))
                ->by((string) $request->user()?->id));
        }

        // The login form's throttle keys an e-mail and an address together;
        // this one keeps a single address from trying every e-mail in turn.
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(self::limit('login_per_ip'))
            ->by((string) $request->ip()));

        // The reset link goes into someone's mailbox, so it carries the
        // instance's address; the request's host is the sender's to choose.
        ResetPassword::createUrlUsing(function (mixed $user, string $token): string {
            if (! $user instanceof CanResetPassword) {
                throw new LogicException('A reset link goes to an account.');
            }

            return PublicUrl::to(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], absolute: false));
        });

        // Sanctum's model lives outside App\Models, so discovery misses it.
        Gate::policy(PersonalAccessToken::class, PersonalAccessTokenPolicy::class);

        // The interactive API docs describe this instance's own surface. Anyone
        // signed in can mint a token, so anyone signed in reads what it does;
        // a guest gets nothing.
        Gate::define('viewApiDocs', fn (?User $user): bool => $user !== null);
    }

    /** Requests a minute, from `tracker.rate_limits`. */
    private static function limit(string $name): int
    {
        return config()->integer("tracker.rate_limits.{$name}");
    }
}
