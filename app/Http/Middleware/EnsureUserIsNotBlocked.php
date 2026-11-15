<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminates sessions of accounts blocked with `vide:user:block` — the
 * command only flips `blocked_at`, so this catches users who were already
 * logged in (or hold a remember-me cookie) when the block happened.
 */
class EnsureUserIsNotBlocked
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isBlocked()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => trans('auth.blocked')]);
        }

        return $next($request);
    }
}
