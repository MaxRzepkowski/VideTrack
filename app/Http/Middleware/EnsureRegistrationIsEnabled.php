<?php

namespace App\Http\Middleware;

use App\Accounts\Registration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationIsEnabled
{
    public function __construct(private readonly Registration $registration) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->registration->isOpen(), 404);

        return $next($request);
    }
}
