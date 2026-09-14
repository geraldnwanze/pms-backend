<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureDemoUser;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveDemoUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Broadcasting auth for the token-based SPA: POST /api/v1/broadcasting/auth,
    // guarded by Sanctum. Channel authorization lives in routes/channels.php.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Legacy demo auth (X-User-Id header) for the /api/* snapshot endpoints
        // the current frontend still uses. The v1 API uses auth:sanctum instead.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            ResolveDemoUser::class,
        ]);

        $middleware->alias([
            'demo.auth' => EnsureDemoUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // This is an API — never redirect an unauthenticated request to a login page.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*') || $request->expectsJson()
            ? response()->json(['message' => 'Unauthenticated.', 'errors' => null], 401)
            : null);

        $exceptions->render(new ApiExceptionRenderer);
    })->create();
