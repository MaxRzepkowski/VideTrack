<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API v1 gates its routes on what a token may do (read / write). The pages
 * reach the same routes with the browser session instead, and a session is
 * the account itself — there is no token to hold it back. A call under api/v1
 * always carries a token (that door has no session), so it is always checked.
 */
class RequireTokenAbility
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null) {
            foreach ($abilities as $ability) {
                if (! $token->can($ability)) {
                    throw new MissingAbilityException($ability);
                }
            }
        }

        return $next($request);
    }
}
