<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole `api/*` surface speaks JSON. Force the Accept header so framework
 * paths that branch on `expectsJson()` (notably unauthenticated handling)
 * always return a JSON body instead of attempting an HTML redirect.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
