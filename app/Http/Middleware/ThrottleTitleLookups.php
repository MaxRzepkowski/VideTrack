<?php

namespace App\Http\Middleware;

use App\Http\Requests\Api\V1\LookupTitlesRequest;
use App\Support\Numbers;
use App\Support\Payload;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * A lookup asks the catalogue once per title, so it is charged per title:
 * as many searches a minute as the pages that fan out to a provider may
 * make. A request-counting limiter would let 25 searches through for one.
 */
class ThrottleTitleLookups
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $titles = min(count(Payload::list($request->input('titles'))), LookupTitlesRequest::MAX_TITLES);
        $key = 'title-lookups:'.(string) ($request->user()->id ?? $request->ip());
        $budget = config()->integer('tracker.rate_limits.provider_pages');

        if ((Numbers::int(RateLimiter::attempts($key)) ?? 0) + $titles > $budget) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::increment($key, amount: $titles);

        return $next($request);
    }
}
