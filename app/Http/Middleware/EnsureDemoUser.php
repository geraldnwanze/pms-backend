<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return response()->json([
                'message' => 'Unauthenticated. Send an X-User-Id header identifying the acting user.',
            ], 401);
        }

        return $next($request);
    }
}
