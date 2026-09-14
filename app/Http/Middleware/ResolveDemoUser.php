<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo-grade authentication.
 *
 * The frontend is a role-picker demo with a fixed set of seeded users and no
 * real credentials flow. Each request identifies the acting user with an
 * `X-User-Id` header (falling back to a bearer token that is just the user id).
 * This middleware resolves that user and binds it to the request; it never
 * rejects — route protection is handled by EnsureDemoUser.
 */
class ResolveDemoUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header('X-User-Id')
            ?: $request->bearerToken()
            ?: $request->query('as');

        if ($userId) {
            $user = User::find($userId);

            if ($user) {
                $request->setUserResolver(fn () => $user);
            }
        }

        return $next($request);
    }
}
