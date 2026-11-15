<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetUserLocale
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Guarded by Auth::check(): a guest page (login, a public list)
        // has no account to read a language from.
        $locale = config('tracker.default_locale');

        if (Auth::check()) {
            $locale = Auth::user()->locale ?? $locale;
        }

        if (is_string($locale) && in_array($locale, config()->array('tracker.supported_locales'), true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
