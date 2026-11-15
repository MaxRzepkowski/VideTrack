<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token counterpart of EnsureUserIsNotBlocked: blocking an account has to
 * cut off its tokens too. Separate from the web middleware because that one
 * logs the session out, and a token request has no session to invalidate.
 */
class EnsureApiTokenUserIsNotBlocked
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isBlocked() === true, 403, trans('auth.blocked'));

        return $next($request);
    }
}
