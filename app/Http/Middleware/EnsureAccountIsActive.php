<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated account (status = inactive) is locked out everywhere, not just at the sign-in screen:
 * a token it already holds stops working the moment an administrator deactivates it.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status === 'inactive') {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'This account has been deactivated.', 'errors' => null], 401);
        }

        return $next($request);
    }
}
