<?php

namespace App\Http\Middleware;

use App\Library\MutationContext;
use App\Library\MutationSource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks everything written during an API request as coming from the API.
 * The actions themselves take no source parameter — declaring it is the
 * entry layer's job, and this is the entry layer for tokens.
 */
class SetApiMutationSource
{
    public function __construct(
        private readonly MutationContext $mutations,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->mutations->run(
            MutationSource::api(),
            fn (): Response => $next($request),
        );
    }
}
