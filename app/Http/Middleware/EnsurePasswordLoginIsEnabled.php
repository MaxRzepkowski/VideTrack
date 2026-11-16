<?php

namespace App\Http\Middleware;

use App\Accounts\PasswordLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Closes the password's routes on an instance entered through identity providers only. */
class EnsurePasswordLoginIsEnabled
{
    public function __construct(private readonly PasswordLogin $passwordLogin) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->passwordLogin->isEnabled(), 404);

        return $next($request);
    }
}
