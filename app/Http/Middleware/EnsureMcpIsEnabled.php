<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Closes the MCP server on an instance whose operator turned it off (MCP_ENABLED). */
class EnsureMcpIsEnabled
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('tracker.mcp_enabled') === true, 404);

        return $next($request);
    }
}
