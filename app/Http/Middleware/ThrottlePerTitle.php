<?php

namespace App\Http\Middleware;

use App\Support\Numbers;
use App\Support\Payload;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A route that may ask the catalogue once per title it is given is charged
 * per title: as many titles a minute as the pages that fan out to a provider
 * may make. A request-counting limiter would let a whole list of titles
 * through for the price of one. Each route passes its own key, so one
 * route's count does not drain another's.
 */
class ThrottlePerTitle
{
    /** The middleware for a route whose titles come as the list in `$field`, at most `$most` of them. */
    public static function using(string $field, int $most, string $key): string
    {
        return self::class.':'.implode(',', [$field, $most, $key]);
    }

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $field, string $most, string $key): Response
    {
        // A route written with a bad maximum must not switch its limit off.
        $cap = Numbers::int($most) ?? throw new InvalidArgumentException("Not a number of titles: [{$most}].");
        $titles = min(count(Payload::list($request->input($field))), $cap);
        $bucket = $key.':'.(string) ($request->user()->id ?? $request->ip());
        $budget = config()->integer('tracker.rate_limits.provider_pages');

        if ((Numbers::int(RateLimiter::attempts($bucket)) ?? 0) + $titles > $budget) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($bucket)]);
        }

        RateLimiter::increment($bucket, amount: $titles);

        return $next($request);
    }
}
