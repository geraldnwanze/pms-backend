<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the legacy passwordless demo API outside local/testing/development —
 * it identifies the caller from a bare user id (see ResolveDemoUser), which is
 * only acceptable on a developer machine. Mirrors the gate on the v1
 * passwordless login (AuthController::login / demoUsers).
 */
class DemoEnvironmentOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment('local', 'testing', 'development'), 404);

        return $next($request);
    }
}
