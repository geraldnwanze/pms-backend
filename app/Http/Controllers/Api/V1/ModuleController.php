<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The switchable modules. Anyone signed in may read which are on (the app needs it to hide what is off); only a
 * superadmin may switch one.
 */
class ModuleController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly Modules $modules, private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        return $this->ok($this->modules->describe(), 'Modules.');
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a superadmin can switch modules on or off.');
        abort_unless(Modules::exists($key), 404, 'Unknown module.');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'cascade' => ['sometimes', 'boolean'],
        ]);

        $changed = $this->modules->set($key, (bool) $data['enabled'], (bool) ($data['cascade'] ?? false));

        foreach ($changed as $k) {
            $this->audit->record($request->user(), 'edit', 'Module', $k,
                ($data['enabled'] ? 'Turned on' : 'Turned off').' the '.Modules::DEFINITIONS[$k]['label'].' module');
        }

        return $this->ok($this->modules->describe(), $changed === [] ? 'No change.' : 'Modules updated.', ['changed' => $changed]);
    }
}
