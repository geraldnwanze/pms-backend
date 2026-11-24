<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:utilities` — refuse the request when that module is turned off. Everyone is refused, superadmins
 * included: off means off, and the Modules screen is where they turn it back on.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly Modules $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->modules->enabled($module)) {
            return response()->json([
                'message' => 'The '.Modules::DEFINITIONS[$module]['label'].' module is turned off.',
                'errors' => null,
                'module' => $module,
            ], 403);
        }

        return $next($request);
    }
}
